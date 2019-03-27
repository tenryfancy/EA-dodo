<?php
namespace app\publish\service;

use think\Db;
use think\Exception;
use app\publish\service\EbayHelper;
use service\ebay\EbayApi;
use app\common\model\ebay\EbayAccount;
use app\common\model\ebay\EbayCategory;
use app\common\model\ebay\EbaySite;
use app\common\model\ebay\EbayListing;
use app\common\model\ebay\EbayListingImage;
use app\common\model\ebay\EbayListingSetting;
use app\common\model\ebay\EbayListingSpecifics;
use app\common\model\ebay\EbayListingTransport;
use app\common\model\ebay\EbayListingTransportIn;
use app\common\model\ebay\EbayListingVariation;
use app\common\model\Channel;
use app\common\model\Goods;
use app\common\model\GoodsSkuMap;
use app\common\model\GoodsSkuAlias;
use app\common\model\GoodsSku;
use app\common\model\Currency;
use app\common\cache\Cache;
use think\cache\driver;
use app\common\model\ebay\EbayCategorySpecific;
use app\common\model\ebay\EbayCategorySpecificDetail;
use app\common\model\ebay\EbayCustomCategory;
use app\common\model\ebay\EbayTrans;
use app\common\model\ebay\EbayCountry;
use app\common\model\ebay\EbayExcludeShiplocation;
use app\common\model\ebay\EbayHistoryCategory;
use app\common\model\ebay\EbayVatInfo;
use app\common\model\ebay\EbayDispatchTimeMaxDetails;
use app\common\model\paypal\PaypalAccount;
use app\index\service\AccountService;
use app\index\service\MemberShipService;
use app\common\traits\User;
use app\publish\service\EbayCategorySearch;
use app\common\model\ebay\OeNumber;
use app\common\model\ebay\OeNumberVechile;
use app\common\model\ebay\OeVechile;
use app\common\model\User as UserModel;
use app\index\service\Role;
use app\common\model\ChannelUserAccountMap;
use app\common\service\UniqueQueuer;
use app\publish\queue\EbayGetItemQueue;
use app\common\service\Common;



/** 
 * @module 刊登系统
 * @title Ebay刊登-Listing管理
 * @author zengshaohui
 * @url /Publish
 * Date: 2017/5/17
 * Time: 11:04
 */

class EbayService{

    use User;
    /**
     * @title 获取商户类目
     * @param $parentId 【父级ID】
     * @param $siteId  【站点】
     */
    public function getEbayCategorys($parentId,$siteId)
    {
        if($parentId==0){
            $wh['category_level']=1;
        }else{
            $wh['category_parent_id'] = $parentId;
        }
        $wh['site'] = $siteId;
        $wh['category_id'] = ['<>',$parentId];
        return (new EbayCategory())
        ->field("category_id,category_name,variations_enabled,leaf_category,best_offer_enabled,item_compatibility_enabled")
        ->where($wh)->order('category_name')->select();
    }

    /**
     * @title 获取类目属性
     * @param $categoryId 【类目ID】
     * @param $siteId  【站点】
     */
    public function getEbaySpecifics($categoryId,$siteId)
    {
        $wh['category_id'] = $categoryId;
        $wh['site'] = $siteId;
        $spec = (new EbayCategorySpecific())
        ->where($wh)->select();

        if(!$spec){#如果不存在，则调用接口
            $help = new EbayHelper();
            $cate = (new EbayCategory())->where($wh)->find();
            $help->getEbaySpecifics($cate);
            $spec = (new EbayCategorySpecific())
            ->where($wh)->select();
        }

        foreach($spec as $k => $v){
            $spec[$k]['detail']=(new EbayCategorySpecificDetail())
            ->field("category_specific_value,parent_name,parent_value")
            ->where(["ebay_specific_id"=>$v['id']])->select();
        }
        return $spec;
    }

    /**
     *@title 获取ebay站点
     */
    public function getEbaySites()
    {
        return (new EbaySite())->alias("s")
        ->join("currency c","s.currency=c.code","LEFT")
        ->field("c.official_rate,s.country,s.siteid,s.abbreviation,s.currency,s.name,s.symbol,s.time_zone")
        ->where(['show'=>1])
        ->select();
    }

    /**
     * @title 获取账号自定义类目
     * @param $categoryId 【类目ID】
     * @param $accountId  【账号ID】
     */
    public function getEbayCusCategory($categoryId,$accountId)
    {
        return (new EbayCustomCategory())
        ->where(["parent_id"=>$categoryId,"account_id"=>$accountId])->select();
    }

    /**
     * @title 获取站点的物流方式
     * @param $site 【站点ID】
     */
    public function getTrans($site)
    {
        return (new EbayTrans())->where(["site"=>$site])->select();
    }

    /**
     * @title 获取ebay的国家代码
     */
    public function getCountrys()
    {
        return (new EbayCountry())->select();
    }

    /**
     * @title 获取ebay的物流运送国家
     */
    public function getLocations()
    {
        return (new EbayExcludeShiplocation())->select();
    }

    /**
     * @title 获取ebay平台销售账号
     */
    public function getEbayAccount($userId,$site)
    {
//        try {
//            $users = $this->getUnderlingInfo($userId);
//            $memberShipService = new MemberShipService();
//            #所有所属下级销售账号
//            $accountList = [];
//            foreach ($users as $k => $user) {
//                $temp = $memberShipService->getAccountIDByUserId($user, 1);
//                $accountList = array_merge($temp, $accountList);
//            }
//            $acService = new AccountService();
//            #已启用已授权账号
//            $accountIds = $acService->accountInfo(1);
//            #已绑定销售员的账号
//            $saleAccount = [];
//            foreach ($accountIds['account'] as $ac) {
//                if (in_array($ac['value'], $accountList)) {
//                    $temp2 = $memberShipService->member(1, $ac['value'], 'sales');
//                    if (count($temp2) > 0) {
//                        $tempSale['id'] = $ac['value'];
//                        $tempSale['code'] = $ac['label'];
//                        $saleAccount[] = $tempSale;
//                        unset($tempSale);
//                    }
//                }
//            }
//            return $saleAccount;
//        } catch (Exception $e) {
//            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
//        }
        try {
            $admin = (new Role())->isAdmin($userId) || UserModel::where('id',$userId)->value('job') == 'IT';
            if ($admin) {
                //如果是超级管理员或软件工程师，获取该平台下所有的账号，包括客服，采购
                $accountUserIds = ChannelUserAccountMap::where('channel_id',1)->column('account_id,seller_id','id');
                $allAccountIds = array_column($accountUserIds,'account_id');
                $allUserIds = array_column($accountUserIds,'seller_id');
            } else {//其它角色
                $memberShipService = new MemberShipService();
                $allUserIds = $this->getUnderlingInfo($userId);//下级人员id
            }
            //过滤掉停用和不是销售员的用户
            $whUser['status'] = 1;
            $whUser['job'] = 'sales';
            $whUser['id'] = ['in', $allUserIds];
            $validUserIds = UserModel::where($whUser)->column('id');

            if (!$admin) {
                $allAccountIds = [];
                $jobs = UserModel::whereIn('id',$validUserIds)->column('job','id');
                foreach ($validUserIds as $validUserId) {
                    if (!isset($jobs[$validUserId]) || $jobs[$validUserId] != 'sales') {//不是销售不处理
                        continue;
                    }
                    $subAccountIds = $memberShipService->getAccountIDByUserId($validUserId, 1);
                    $allAccountIds = array_merge($subAccountIds, $allAccountIds);
                }
                $allAccountIds = array_unique($allAccountIds);
            }
            //过滤掉停用和未授权的账号
            $wh['is_invalid'] = 1;
            $wh['account_status'] = 1;
            $wh['id'] = ['in', $allAccountIds];
            $wh['token'] = ['neq', ''];
            $validAccountIds = EbayAccount::where($wh)->column('id');
            if ($admin) {
                foreach ($accountUserIds as $accountUserId) {
                    if (in_array($accountUserId['account_id'], $validAccountIds)
                        && in_array($accountUserId['seller_id'], $validUserIds)) {
                        $accountIds[] = $accountUserId['account_id'];
                    }
                }
            } else {
                $accountIds = $validAccountIds;
            }

            $accounts = EbayAccount::field('id,code')->whereIn('id',$accountIds)->select();
            return $accounts;
        } catch (Exception $e) {
            return ['result'=>false, 'message'=>$e->getFile().'|'.$e->getLine().'|'.$e->getMessage()];
        }
    }

    /**
     * @title 获取ebay平台历史账号
     * @param查询条件
     */
    public function getEbayHistoryCategory($param)
    {
        return (new EbayHistoryCategory())->field("id,category_id,category_name")
        ->where($param)->order("update_date desc")->select();
    }

    /**
     * @title 获取ebay站点对应的增值税选项
     * @param查询条件
     */
    public function getEbayVatInfo($site)
    {
        return (new EbayVatInfo())
        ->where(['site'=>$site])->select();
    }

    /**
     * @title 获取ebay类目树
     * @param $categoryId 类目ID
     * @param $cateStrids 
     * @param $site 站点
     */
    public function getEbayCategoryTree($categoryId,$categoryIds,$site)
    {
        $cateInfo = (new EbayCategory())->where(['category_id'=>$categoryId,'site'=>$site])->find();
        if($cateInfo['category_id']!=$cateInfo['category_parent_id']){
            $categoryIds = $this->getEbayCategoryTree($cateInfo['category_parent_id'],$categoryIds,$site);
        }
        return $categoryIds.=" >> ".$cateInfo['category_name'];
    }

    /**
     * @title 根据关键词查询ebay商户类目
     * @param $key
     * @param $site 站点
     */
    public function getCategoryByKeyword($key,$site,$start,$size)
    {
        if(!$key){
            return ['rows'=>[],'count'=>0];
        }

        $categoryId = intval($key);
        $cateInfo = (new EbayCategory())->where(['category_id'=>$categoryId,'site'=>$site])->find();
        if($categoryId>0){
            $tempStr = $this->getEbayCategoryTree($categoryId,"",$site);
            if($tempStr){
                $category['category_name'] = $categoryId."-".substr($tempStr,3);
                $category['category_id'] = $categoryId;
                $category['variations_enabled'] = $cateInfo['variations_enabled'];#是否支持多属性
                $category['item_compatibility_enabled'] = $cateInfo['item_compatibility_enabled'];#是否支持汽车配件兼容信息
            }else{
                $category = [];
            }
            return ['rows'=>[$category],'count'=>1];
        }

        $cateSearch = new EbayCategorySearch();
        $categorys = $cateSearch->query($key,$site,10);
        $categoryIds = [];
        foreach($categorys as $cate){
            $categoryIds[] = $cate['category_id'];
        }

        $categoryInfoArr = [];
        $i=0;
        foreach($categoryIds as $cate){
            $tempStr = $this->getEbayCategoryTree($cate,"",$site);
            $cateInfos = (new EbayCategory())->where(['category_id'=>$cate,'site'=>$site])->find();
            if(!empty(trim(substr($tempStr,3)))){
                $categoryInfoArr[$i]['category_name']=$cate."-".substr($tempStr,3);
                $categoryInfoArr[$i]['variations_enabled']=$cateInfos['variations_enabled'];
                $categoryInfoArr[$i]['item_compatibility_enabled']=$cateInfos['item_compatibility_enabled'];
                $categoryInfoArr[$i++]['category_id']=$cate;
            }
        }
        $res['rows']=$categoryInfoArr;
        $res['count']=count($categoryIds);
        return $res;
    }


    /**
     * @title 获取ebay账号自定义类目树
     * @param $accountId 类目ID
     * @param $categoryId 
     * @param $str
     */
    public function getCustomCateTree($accountId,$categoryId,$str)
    {
        $cateInfo = (new EbayCustomCategory())
        ->where(['category_id'=>$categoryId,'account_id'=>$accountId])->find();
        if($cateInfo['category_id']!=$cateInfo['parent_id']){
            $str = $this->getCustomCateTree($cateInfo['parent_id'],$accountId,$str);
        }
        return $str.=" > ".$cateInfo['name'];
    }

    /**
     * @title 获取ebay备货时间
     * @param $site 站点
     */
    public function getEbayDispatchTimeMax($site)
    {
        return (new EbayDispatchTimeMaxDetails())
        ->field("dispatch_time_max,description")
        ->where(['siteid'=>$site])->select();
    }

    /**
     * @title 获取ebay Paypal账号
     * @param $accountId 账号ID
     * @param $userId 用户ID
     */
    public function getEbayPaypals($accountId,$userId)
    {
        if($accountId){
            $wh = [];
            $memberShipService = new MemberShipService();
            $warehouse = $memberShipService->warehouseTypeBySales(1,$accountId,$userId);
            $ac = (new EbayAccount())->where(['id'=>$accountId])->find();
            $minPay=is_array(json_decode($ac['min_paypal_id'],true))?json_decode($ac['min_paypal_id'],true):[$ac['min_paypal_id']];
            $maxPay=is_array(json_decode($ac['max_paypal_id'],true))?json_decode($ac['max_paypal_id'],true):[$ac['max_paypal_id']];
            $type1_min = [];#本地仓(小额)
            $type1_max = [];#本地仓(大额)
            $type2_min = [];#海外仓(小额)
            $type2_max = [];#海外仓(大额)
            foreach($minPay as $min){
                if(isset($min['type'])){
                    if($min['type']==1){
                        $type1_min[] = $min['id'];
                    }else if($min['type']==2){
                        $type2_min[] = $min['id'];
                    }
                }else{
                    $type1_min[] = $min;
                    $type2_min[] = $min;
                 }
            }
            foreach($maxPay as $max){
                if(isset($max['type'])){
                    if($max['type']==1){
                        $type1_max[] = $max['id'];
                    }else if($max['type']==2){
                        $type2_max[] = $max['id'];
                    }
                }else{
                    $type1_max[] = $max;
                    $type2_max[] = $max;
                 }
            }
            $minIds = [];
            $maxIds = [];
            if($warehouse==0){#所有
                $minIds = array_merge($type1_min,$type2_min);
                $maxIds = array_merge($type1_max,$type2_max);
            }else if($warehouse==1){#本地仓
                $minIds = $type1_min;
                $maxIds = $type1_max;
            }else if($warehouse==2){#海外
                $minIds = $type2_min;
                $maxIds = $type2_max;
            }else{
                $minIds = array_merge($type1_min,$type2_min);
                $maxIds = array_merge($type1_max,$type2_max);
            }
            $rows['min_paypals'] = (new PaypalAccount())->where(['id'=>['in',$minIds]])->select();
            $rows['max_paypals'] = (new PaypalAccount())->where(['id'=>['in',$maxIds]])->select();
            $currency = json_decode($ac['currency'],true);
            $rows['currency'] = is_array($currency)?$currency:[];
        }else{
            $rows['min_paypals'] = (new PaypalAccount())->select();
            $rows['max_paypals'] = (new PaypalAccount())->select();
            $rows['currency'] = [];
        }
        return $rows;
    }

    /**
     * @title 获取ebay 退货时间
     * @param $site 站点ID
     */
    public function getEbayWithIn($siteId)
    {
        return Db::name("ebay_returns_within")->where(['site'=>$siteId])->select();
    }

    /**
     * @title 获取ebay 在线listing,用于同步数据
     * @param $list
     * #verb getItem
     */
    public function getItem($itemId,$accountId)
    {
        try{
            set_time_limit(0); 
            $verb = "GetItem";
            #获取账号token
            $t = Cache::store('EbayAccount')->getTableRecord($accountId);
            $tokenArr = json_decode($t['token'],true);
            $token = trim($tokenArr[0])?$tokenArr[0]:$t['token'];
            $config['devID']=$t['dev_id'];
            $config['appID']=$t['app_id'];
            $config['certID']=$t['cert_id'];
            $config['userToken']=$token;
            $config['compatLevel']=957;
            $config['siteID']=0;
            $config['verb']=$verb;
            $config['appMode']=0;
            $config['account_id']=$t['id'];
            $ebayApi = new EbayApi($config);#创建API请求对象
            $xml = $this->createXmlGetItem($itemId,$token);
            $resText = $ebayApi->createHeaders()->__set("requesBody",$xml)->sendHttpRequest2();
            #echo "<pre>";print_r($resText);#die;
            if(isset($resText['GetItemResponse']['Item'])){
                $helper = new EbayListingCommonHelper($accountId);
                $Item = $resText['GetItemResponse']['Item'];
                $listingData = $helper->syncEbayListing($Item);
                $helper->syncListingData($listingData);
                $listingId = $helper->listingId;
                $list = $listingData['listing'];
                $list['id'] = $listingId;
                $info['list'] = $list;
                $info['varians'] = (new EbayListingVariation())->where(['listing_id'=>$listingId])->select();
                if($list['variation']){
                    $variansOne =  $info['varians'][0]->toArray();
                    $info['list']['v_varkey'] = array_keys(json_decode($variansOne['variation'],true));
                }
                return ["result"=>true,"listing_id"=>$listingId,"info"=>$info];
            }
        }catch(Exception $e){
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @title 获取ebay 在线listing,用于同步数据
     * @param $itemId
     * @param $accountId
     */
    public function getItemByItemid($itemId,$accountId)
    {
        try{
            set_time_limit(0); 
            $verb = "GetItem";
            #获取账号token
            $t = Cache::store('EbayAccount')->getTableRecord($accountId);
            $tokenArr = json_decode($t['token'],true);
            $token = trim($tokenArr[0])?$tokenArr[0]:$t['token'];
            $config['devID']=$t['dev_id'];
            $config['appID']=$t['app_id'];
            $config['certID']=$t['cert_id'];
            $config['userToken']=$token;
            $config['compatLevel']=957;
            $config['siteID']=0;
            $config['verb']=$verb;
            $config['appMode']=0;
            $config['account_id']=$t['id'];
            $ebayApi = new EbayApi($config);#创建API请求对象
            $xml = $this->createXmlGetItem($itemId,$token);#echo $xml;die;
            $resText = $ebayApi->createHeaders()->__set("requesBody",$xml)->sendHttpRequest2();
            #echo "<pre>";print_r($resText);die;
            if(isset($resText['GetItemResponse']['Item'])){
                $helper = new EbayListingCommonHelper($accountId);
                $Item = $resText['GetItemResponse']['Item'];
                $listingData = $helper->syncEbayListing($Item);
                $helper->syncListingData($listingData,1);
                $listingId = $helper->listingId;
                $list = $listingData['listing'];
                $list['id'] = $listingId;
                $info['list'] = $list;
                $info['varians'] = (new EbayListingVariation())->where(['listing_id'=>$listingId])->select();
                if($list['variation']){
                    $variansOne = $info['varians'][0];
                    $info['list']['v_varkey'] = array_keys(json_decode($variansOne['variation'],true));
                }
                return ["result"=>true,"listing_id"=>$listingId,"info"=>$info];
            }
        }catch(Exception $e){
            throw new Exception($e->getFile()."|".$e->getLine()."|".$e->getMessage());
        }
    }

    /**
     * @title 获取ebay 在线listing,用于同步OE
     * @param $itemId
     * @param $accountId
     */
    public function getOeByitemId($itemId,$accountId=230)
    {
        try{
            set_time_limit(0); 
            $verb = "GetItem";
            #获取账号token
            $t = Cache::store('EbayAccount')->getTableRecord($accountId);
            $tokenArr = json_decode($t['token'],true);
            $token = trim($tokenArr[0])?$tokenArr[0]:$t['token'];
            $config['devID']=$t['dev_id'];
            $config['appID']=$t['app_id'];
            $config['certID']=$t['cert_id'];
            $config['userToken']=$token;
            $config['compatLevel']=957;
            $config['siteID']=0;
            $config['verb']=$verb;
            $config['appMode']=0;
            $config['account_id']=$t['id'];
            $ebayApi = new EbayApi($config);#创建API请求对象
            $xml = $this->createXmlGetItem($itemId,$token);
            $resText = $ebayApi->createHeaders()->__set("requesBody",$xml)->sendHttpRequest2();
            if(isset($resText['GetItemResponse']['Item'])){
                return $resText['GetItemResponse']['Item']['ItemCompatibilityList']['Compatibility'];
            }else{
                return [];
            }
        }catch(Exception $e){
            throw new Exception($e->getMessage());
        }
    }

    /*
    *title 创建获取在线listing信息的xml
    *@param itemId
    *@param token 账号秘钥
    */
    public function createXmlGetItem($itemId,$token)
    {
        $xml ='<?xml version="1.0" encoding="utf-8"?>';
        $xml.='<GetItemRequest xmlns="urn:ebay:apis:eBLBaseComponents">';
        $xml.='<IncludeItemCompatibilityList>true</IncludeItemCompatibilityList>';
        $xml.='<IncludeItemSpecifics>true</IncludeItemSpecifics>';
        $xml.='<IncludeTaxTable>true</IncludeTaxTable>';
        $xml.='<IncludeWatchCount>true</IncludeWatchCount>';
        $xml.='<RequesterCredentials>';
        $xml.='<eBayAuthToken>'.$token.'</eBayAuthToken>';
        $xml.='</RequesterCredentials>';
        $xml.='<ItemID>'.$itemId.'</ItemID>';
        $xml.='<DetailLevel>ReturnAll</DetailLevel>';
        $xml.='<ErrorLanguage>en_US</ErrorLanguage>';
        $xml.='<WarningLevel>High</WarningLevel>';
        $xml.='</GetItemRequest>';
        return $xml;
    }

    /*
    *title 同步listing信息
    *@param list listing详细信息
    *@param account_id 销售账号ID
    */
    public function syncEbayListing($list,$account_id,$draft=0)
    {
        $site = new EbaySite();
        $siteInfo = $site->get(array("country"=>$list['Site']));
        $listingSku = $list['SKU'];
        #$goods = $this->getGoodsInfoBysku($listingSku,$account_id);
        $listing['account_id']=$account_id;
        #$listing['site_code']=$list['Site'];
        $listing['site']=intval($siteInfo['siteid']);
        $listing['item_id']=$list['ItemID'];

        // #商品信息
        // if(!empty($goods)){
        //     $goodInfo=$goods['goodInfo'];
        //     $skuInfo=$goods['goodSku'];
        //     $listing['goods_id']=$goodsInfo->id;
        //     $listing['spu']=$skuInfo->spu;
        //     $listing['sku']=$skuInfo->sku;
        // }
        $listing['listing_sku']=$listingSku;
        $listing['draft'] = $draft;

        #币种
        #$cur = new Currency();
        #$currInfo = $cur->get(array("code"=>$list['Currency']));
        $listing['currency']=$list['Currency'];

        if(isset($list['Variations'])){#判断是否为多属性产品
            $listing['variation']=1;
        }else{
            $listing['variation']=0;
        }

        #listing基本信息
        $listing['paypal_emailaddress']=isset($list['PayPalEmailAddress'])?$list['PayPalEmailAddress']:"";
        $listing['primary_categoryid']=$list['PrimaryCategory']['CategoryID'];
        $listing['quantity']=$list['Quantity'];
        $listing['sold_quantity']=intval($list["SellingStatus"]["QuantitySold"]);
        $listing['buy_it_nowprice']=$list["BuyItNowPrice"];#一口价
        $listing['start_price']=$list["StartPrice"];#起始价
        $listing['reserve_price']=isset($list['ReservePrice'])?$list['ReservePrice']:0;#保留价
        $listing['img']=isset($list["PictureDetails"]["GalleryURL"])?$list["PictureDetails"]["GalleryURL"]:"";
        $listing['title']=$list['Title'];#标题
        $listing['hit_count']=isset($list['HitCount'])?$list['HitCount']:0;#点击量
        $listing['watch_count']=isset($list['WatchCount'])?$list['WatchCount']:0;#收藏量
        $listing['listing_type']=$list['ListingType']=="FixedPriceItem"?1:2;#刊登类型
        $listing['store_category_id']=isset($list['Storefront']['StoreCategoryID'])?$list['Storefront']['StoreCategoryID']:0;
        $listing['store_category2_id']=isset($list['Storefront']['StoreCategory2ID'])?$list['Storefront']['StoreCategory2ID']:0;
        $listing['start_date']=strtotime($list['ListingDetails']['StartTime']);
        $listing['end_date']=strtotime($list['ListingDetails']['EndTime']);
        $listCode = $list['SellingStatus']['ListingStatus'];
        if($listCode=="Active"){#在线
            $listing['listing_status']=3;
        }else if($listCode=="Completed"){#下架,已退回成交费
            $listing['listing_status']=11;
        }else if($listCode=="Custom"){#保留
            $listing['listing_status']=10;
        }else if($listCode=="CustomCode"){#定制编号
            $listing['listing_status']=10;
        }else if($listCode=="Ended"){#下架,未退回成交费
            $listing['listing_status']=11;
        }
        $listing["location"]=$list["Location"];#商品所在地
        $listing["dispatch_max_time"]=$list["DispatchTimeMax"]?$list["DispatchTimeMax"]:0;#发货处理时间(dispatch_time_max)
        $listing["country"]=$list["Country"];#发货国家代码
        switch ($list['ListingDuration']) {
            case 'GTC':
                $listing['listing_duration']=1;
                break;
            case 'Days_1':
                $listing['listing_duration']=2;
                break;
            case 'Days_3':
                $listing['listing_duration']=3;
                break;
            case 'Days_5':
                $listing['listing_duration']=4;
                break;
            case 'Days_7':
                $listing['listing_duration']=5;
                break;
            case 'Days_10':
                $listing['listing_duration']=6;
                break;
            case 'Days_30':
                $listing['listing_duration']=7;
                break;
            default:
                break;
        }

        #图片
        $images = array();
        if(isset($list["PictureDetails"]["PictureURL"])){
            $images=is_array($list["PictureDetails"]["PictureURL"])?$list["PictureDetails"]["PictureURL"]:array($list["PictureDetails"]["PictureURL"]);
        }

        #类目属性
        $specifics=[];
        if(isset($list['ItemSpecifics']['NameValueList'])){
            $NameValueList = isset($list['ItemSpecifics']['NameValueList'][0])?$list['ItemSpecifics']['NameValueList']:[$list['ItemSpecifics']['NameValueList']];
            foreach($NameValueList as $spec){
                #$tempSpec[$spec['Name']]=$spec['Value'];
                $tempSpec['attr_name'] = $spec['Name'];
                $tempSpec['attr_value'] = $spec['Value'];
                $tempSpec['custom'] = 0;
                $specifics[] = $tempSpec;
                unset($tempSpec);
            }
        }
        #运输
        $International = array();
        $detail = array();
        $detail['application_data'] = isset($list['ApplicationData'])?$list['ApplicationData']:"";#应用名称
        $detail['condition_id']=isset($list['ConditionID'])?$list['ConditionID']:'';
        $detail['condition_description']=isset($list['ConditionDescription'])?$list['ConditionDescription']:"";
        $detail['description']=$list['Description'];#描述
        $detail["payment_method"]=json_encode($list["PaymentMethods"]);#付款方式
        if(isset($list["ShippingDetails"])){
            $ShippingDetails=$list["ShippingDetails"];
            $detail["payment_instructions"]=isset($ShippingDetails["PaymentInstructions"])?$ShippingDetails["PaymentInstructions"]:"";
            if(isset($ShippingDetails["ExcludeShipToLocation"])){#不送达地区
                $detail["exclude_location"]=json_encode($ShippingDetails["ExcludeShipToLocation"]);
            }

            $transIn = array();
            if(isset($ShippingDetails["ShippingServiceOptions"])){//国内运输
                $ship=isset($ShippingDetails["ShippingServiceOptions"][0])?$ShippingDetails["ShippingServiceOptions"]:array($ShippingDetails["ShippingServiceOptions"]);
                foreach($ship as $ksh => $vsh){
                    $transIn[$ksh]['shipping_service']=$vsh['ShippingService'];
                    $transIn[$ksh]['shipping_service_cost']=isset($vsh['ShippingServiceCost'])?$vsh['ShippingServiceCost']:0;
                    $transIn[$ksh]['shipping_service_additional_cost']=isset($vsh['ShippingServiceAdditionalCost'])?$vsh['ShippingServiceAdditionalCost']:0;
                    $transIn[$ksh]['shipping_service_priority']=isset($vsh['ShippingServicePriority'])?$vsh['ShippingServicePriority']:0;
                    $transIn[$ksh]['expedited_service']=isset($vsh['ExpeditedService'])?($vsh['ExpeditedService']=="true"?1:0):0;
                    $transIn[$ksh]['shipping_time_min']=isset($vsh['ShippingTimeMin'])?$vsh['ShippingTimeMin']:0;
                    $transIn[$ksh]['shipping_time_max']=isset($vsh['ShippingTimeMax'])?$vsh['ShippingTimeMax']:0;
                    $shipping[$ksh]['extra_cost'] = isset($vsh['ShippingSurcharge'])?$vsh['ShippingSurcharge']:0;
                    $transIn[$ksh]['free_shipping']=isset($vsh['FreeShipping'])?($vsh['FreeShipping']=="true"?1:0):0;
                }
                $detail['shipping'] = json_encode($transIn);
            }
            if(isset($ShippingDetails["InternationalShippingServiceOption"])){#国际运输
                $InternationalShippingServiceOption=isset($ShippingDetails["InternationalShippingServiceOption"][0])?$ShippingDetails["InternationalShippingServiceOption"]:array($ShippingDetails["InternationalShippingServiceOption"]);
                $i=0;
                foreach($InternationalShippingServiceOption as $in){
                    $International[$i]["shipping_service"]=$in["ShippingService"];
                    if(isset($in["ShippingServiceAdditionalCost"])){
                        $International[$i]["shipping_service_additional_cost"]=$in["ShippingServiceAdditionalCost"];
                    }
                    if(isset($in["ShippingServiceCost"])){
                        $International[$i]["shipping_service_cost"]=$in["ShippingServiceCost"];
                    }
                    $International[$i]["shipping_service_priority"]=$in["ShippingServicePriority"];
                    $ShipToLocation=is_array($in["ShipToLocation"])?implode(",",$in["ShipToLocation"]):$in["ShipToLocation"];
                    $International[$i]["shiptolocation"]=$ShipToLocation;
                    $i++;
                }
                $detail['international_shipping'] = json_encode($International);
            }
            #$detail["internal"]=1;
        }else{
            #$detail["internal"]=0;
        }

        #退换货政策
        if(isset($list["ReturnPolicy"])){
            $ReturnPolicy=$list["ReturnPolicy"];
            #退款详情
            if(isset($ReturnPolicy["Description"])){
                $detail["return_description"]=$ReturnPolicy["Description"];
            }else{
                $detail["return_description"]="";
            }
            #退款方式
            if(isset($ReturnPolicy["RefundOption"]))$detail["return_type"]=$ReturnPolicy["RefundOption"];
            #退款天数
            $reTime = isset($ReturnPolicy["ReturnsWithinOption"])?$ReturnPolicy["ReturnsWithinOption"]:"";
            if($reTime){
                switch ($reTime) {#接受退货周期:(1 Days_14,2 Days_30,3 Days_60,4 Months_1)
                    case 'Days_14':
                        $listing['return_time'] = 1;
                        break;
                    case 'Days_30':
                        $listing['return_time'] = 2;
                        break;
                    case 'Days_60':
                        $listing['return_time'] = 3;
                        break;
                    case 'Months_1':
                        $listing['return_time'] = 4;
                        break;
                    default:
                        break;
                }
            }
            #运费承担方
            $detail["return_shipping_option"]=$ReturnPolicy["ShippingCostPaidByOption"]=="Buyer"?0:1;
            #是否支持退换货
            $detail["return_policy"]=1;
        }else{
            $detail["return_policy"]=0;
        }

        #买家限制
        $detailRequirment = [];
        if(isset($list["BuyerRequirementDetails"])){
            #paypal限制  
            $BuyerRequirementDetails=$list["BuyerRequirementDetails"];
            if(isset($BuyerRequirementDetails["LinkedPayPalAccount"])){
                $detailRequirment["link_paypal"]=$BuyerRequirementDetails["LinkedPayPalAccount"]=="true"?1:0;
            }else{
                $detailRequirment["link_paypal"]=0;
            } 

            #未付款限制
            if(isset($BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"])){
                #次数
                $detailRequirment["strikes_count"]=isset($BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Count"])?$BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Count"]:0;
                #时限
                $detailRequirment["strikes_period"]=isset($BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Period"])?$BuyerRequirementDetails["MaximumUnpaidItemStrikesInfo"]["Period"]:"";
                $detailRequirment["strikes"]=1;
            }else{
                $detailRequirment["strikes"]=0;
            }

            #违反政策相关
            if(isset($BuyerRequirementDetails["MaximumBuyerPolicyViolations"])){
                #次数
                $detailRequirment["violations_count"]=isset($BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Count"])?$BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Count"]:0;
                #时限
                $detailRequirment["violations_period"]=isset($BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Period"])?$BuyerRequirementDetails["MaximumBuyerPolicyViolations"]["Period"]:"";
                $detailRequirment["violations"]=1;
            }else{
                $detailRequirment["violations"]=0;
            }

            #限制条件
            if(isset($BuyerRequirementDetails["MaximumItemRequirements"])){
                $detailRequirment["requirements_max_count"]=isset($BuyerRequirementDetails["MaximumItemRequirements"]["MaximumItemCount"])?$BuyerRequirementDetails["MaximumItemRequirements"]["MaximumItemCount"]:0;

                if(isset($BuyerRequirementDetails["MaximumItemRequirements"]["MinimumFeedbackScore"])){
                    $detailRequirment["minimum_feedback"]=1;
                    $detailRequirment['minimum_feedback_score']=$BuyerRequirementDetails["MaximumItemRequirements"]["MinimumFeedbackScore"];
                }
                $detailRequirment["requirements"]=1;
            }else{
                $detailRequirment["requirements"]=0;
            }

            #信用限制
            if(isset($BuyerRequirementDetails["MinimumFeedbackScore"])){
                $detailRequirment["credit"]=1;
                if(isset($BuyerRequirementDetails["MinimumFeedbackScore"])){
                    $detailRequirment["requirements_feedback_score"]=$BuyerRequirementDetails["MinimumFeedbackScore"];
                }
            }else{
                $detailRequirment["credit"]=0;
            }

            #不在我的配送地
            if(isset($BuyerRequirementDetails["ShipToRegistrationCountry"])){
                $detailRequirment["registration"]=$BuyerRequirementDetails["ShipToRegistrationCountry"]=="true"?1:0;
            }else{
                $detailRequirment["registration"]=0;
            }
            $listing["disable_buyer"]=1;
        }else{
            $listing["disable_buyer"]=0;
        }
        $detail['buyer_requirment_details'] = json_encode($detailRequirment);
        
        $mSpecifics=array();#类目属性与多属性产品记录
        $variationPics = [];
        $vs=array();
        if(isset($list["Variations"])){
            $listing['variation']=1;
            $variations=isset($list["Variations"]["Variation"][0])?$list["Variations"]["Variation"]:array($list["Variations"]["Variation"]);
            $i=0;
            foreach($variations as $ia){
                $vs[$i]['sku_id']=0;
                $vs[$i]['channel_map_code']=$ia['SKU'];
                $vs[$i]["v_price"]=$ia["StartPrice"]?$ia["StartPrice"]:0;
                $vs[$i]["v_qty"]=$ia["Quantity"]?$ia["Quantity"]:0;
                $vs[$i]["v_sold"]=intval($ia["SellingStatus"]["QuantitySold"]);
                if(isset($ia["VariationProductListingDetails"]["UPC"]))$vs[$i]["upc"]=$ia["VariationProductListingDetails"]["UPC"];
                if(isset($ia["VariationProductListingDetails"]["ISBN"]))$vs[$i]["isbn"]=$ia["VariationProductListingDetails"]["ISBN"];
                if(isset($ia["VariationProductListingDetails"]["EAN"]))$vs[$i]["ean"]=$ia["VariationProductListingDetails"]["EAN"];
                $Specifics=isset($ia["VariationSpecifics"]["NameValueList"][0])?$ia["VariationSpecifics"]["NameValueList"]:array($ia["VariationSpecifics"]["NameValueList"]);
                $temp=array();
                foreach($Specifics as $val){
                    $temp[$val["Name"]]=$val["Value"];
                    $mSpecifics[$val["Name"]]=$val["Value"];
                }
                $vs[$i]["variation"]=json_encode($temp);
                $vs[$i]['unique_code']=md5(json_encode($temp));
                $i++;
            }

            if (isset($list['Variations']) && isset($list['Variations']['Pictures'])) {
                $pictureDetail = $list['Variations']['Pictures'];
                $name = $pictureDetail['VariationSpecificName'];
                foreach($pictureDetail['VariationSpecificPictureSet'] as $set) {
                    $value = $set['VariationSpecificValue'];
                    if (!isset($set['PictureURL']) || !$set['PictureURL']) {
                        continue;
                    }
                    $vaPicList =  is_array($set['PictureURL']) ? $set['PictureURL'] : [$set['PictureURL']];
                    foreach($vaPicList as $k => $img) {
                        $image['name'] = $name;
                        $image['value'] = $value;
                        $image['main'] = 0;
                        $image['main_de'] = 1;
                        $image['url'] = $img;
                        array_push($variationPics, $image);
                        unset($image);
                    }
                }
            }
        }
        #$detail['specifics'] = json_encode($mSpecifics);
        $detail['specifics'] = json_encode($specifics);

        if(isset($vs[0])){#多属性名称
            $vvk = json_decode($vs[0]['variation'],true);
            #$listing['v_varkey'] = json_encode(array_keys($vvk));
        }

        $listingData = array("listing"=>$listing,"images"=>$images,"detail"=>$detail,"variation"=>$vs,"variationPics"=>$variationPics);
        #同步数据库
        return $this->syncListingData($listingData);
    }

    /*
    *title 同步listing数据
    *@param listingData listing信息
    *@param account_id 账号ID
    */
    public function syncListingData($listingData)
    {
        $mList = new EbayListing();
        $mImage = new EbayListingImage();
        $mSet = new EbayListingSetting();
        $mVar = new EbayListingVariation();

        $listing=$listingData['listing'];
        $images=$listingData['images'];
        $detail=$listingData['detail'];
        $variation=$listingData['variation'];
        $variationPics = $listingData['variationPics'];#都属性图片
        #设置信息
        $listingId = $mList->syncListing($listing);
        #$listingId = $mList->insertGetId($listing);
        $detail['id'] = $listingId;
        $mSet->syncSetting($detail);

        #产品主图
        $oImgArr = $mImage->field("id,eps_path")->where(['listing_id'=>$listingId])->select();
        $oImgs = [];
        foreach($oImgArr as $oImg){
            $oImgs[$oImg['eps_path']]=$oImg['id'];
        }
        $i = 0;
        foreach($images as $k => $v){
            if(!isset($oImgs[$v])){
                $i++;
                $tempImg['listing_id']=$listingId;
                #$imgArr[$k]['spu']=isset($listing['spu'])?$listing['spu']:"";
                $tempImg['sku']=isset($listing['sku'])?$listing['sku']:"";
                $tempImg['thumb']=$v;
                $tempImg['eps_path']=$v;
                $tempImg['sort']=$k;
                $tempImg['status']=3;#已上传至eps
                $tempImg['main']=1;
                $tempImg['detail']=0;
                $tempImg['update_time']=time();
                $mImage->insertGetId($tempImg);
                unset($tempImg);
            }else{#已存在
                $mImage->where(['id'=>$oImgs[$v]])->update(['main'=>1,'sort'=>$k]);
            }
        }
        
        #多属性图片
        foreach($variationPics as $kvar => $varImg){
            if(!isset($oImgs[$varImg['url']])){
                $tempImg['listing_id']=$listingId;
                #$imgArr[$k]['spu']=isset($listing['spu'])?$listing['spu']:"";
                $tempImg['sku']=isset($listing['sku'])?$listing['sku']:"";
                $tempImg['thumb']=$varImg['url'];
                $tempImg['eps_path']=$varImg['url'];
                $tempImg['sort']=$i++;
                $tempImg['status']=3;#已上传至eps
                $tempImg['main_de']=1;
                $tempImg['detail']=0;
                $tempImg['name'] = $varImg['name'];
                $tempImg['value'] = $varImg['value'];
                $tempImg['update_time']=time();
                $mImage->insertGetId($tempImg);
                unset($tempImg);
            }else{#已存在
                $mImage->where(['id'=>$oImgs[$v]])->update(['main_de'=>1,'sort'=>$i++]);
            }
        }
        #多属性子产品
        foreach($variation as $kvar => $vvar){
            $mVar->syncListingVarions($vvar,$listingId);
        }
        return $listingId;
    }

    /*
    *title 检查重上信息
    *@param listingId 
    */
    public function listeningRelistItem($listingId)
    {
        $relistQueuer = new EbayRelistQueuer();
        $wh['l.id']=$listingId;
        $wh['s.restart'] = 1;

        $data = (new EbayListing())->alias("l")->join("ebay_listing_setting s","l.id=s.listing_id","LEFT")
        ->field("s.restart,s.restart_rule,s.restart_count,s.restart_way,s.restart_time,s.restart_number,
            l.quantity,l.sold_quantity,l.id")
        ->where($wh)->order("l.update_date")
        ->find();

        if($data){
            $rule = intval($data['restart_rule']);#重上规则
            if($rule==1){#只要物品结束
                $rt = true;
            }else if($rule==2){#所有物品卖出
                if(intval($data['quantity'])==0 && intval($data['sold_quantity'])>0){
                    $rt = true;
                }else{
                    $rt = false;
                }
            }else if($rule==3){#没有物品卖出
                if(intval($data['sold_quantity']==0)){
                    $rt = true;
                }else{
                    $rt = false;
                }
            }else if($rule==4){#没有物品卖出后仅刊登一次
                if(intval($data['sold_quantity']==0) && $data['restart_number']<1){
                    $rt = true;
                }else{
                    $rt = false;
                }
            }else if($rule==5){#当物品卖出一定数量
                if(intval($data['sold_quantity'])>=$data['restart_count']){
                    $rt = true;
                }else{
                    $rt = false;
                }
            }

            if($rt){
                #重上方式
                if($data['restart_way']==1){#立即重上
                    (new EbayListing())->where(['id'=>$data['id']])->update(['listing_status'=>13]);
                    $relistQueuer->production([$listingId]);
                }else if($data['restart_way']==2){#定时重上
                    $th = date("H:i:s",intval($data['restart_time']));#获取时分秒
                    $tm = date('Y-m-d',time());
                    $t = strtotime($th." ".$tm);#获取重上时间
                    $tn = time();
                    if($t<$tn){
                        (new EbayListing())->where(['id'=>$data['id']])->update(['listing_status'=>13,
                            'update_date'=>time()]);
                    }
                    $relistQueuer->production([$listingId]);
                }
            }
        }
    }


    /*
    *title 获取本地映射产品信息
    *@param sku 线上SKU
    *@param account_id 账号ID
    */
    public function getGoodsInfoBysku($sku,$account_id)
    {#以线上sku获取相对映射的产品信息
        $goods = new Goods;
        $goodsSku = new GoodsSku();
        $goodsSkuMap = new GoodsSkuMap();
        $goodsSkuAlias = new GoodsSkuAlias();
        $channel=Cache::store('channel')->getChannel();
        $cha=array();
        foreach($channel as $c){
            if($c['name']=="ebay"){
                $cha=$c;
            }
        }
        $whMap['channel_id']=$cha['id'];
        $whMap['account_id']=$account_id;
        $whMap['channel_sku']=$sku;

        $goodMap = $goodsSkuMap->get($whMap);
        if($goodMap){
            $skuId = $goodMap->sku_id;
        }else{
            $whAli['alias']=$sku;
            $goodAli = $goodsSkuAlias->get($whAli);
            if($goodAli){
                $skuId = $goodAli->sku_id;
            }else{
                $skuId = 0;
            }
        }

        if($skuId!=0){
            $goodSku = $goodsSku->get($skuId);
            if($goodSku){
                $goodInfo=$goods->get($goodSku->goods_id);
                if($goodInfo){
                    $result['goodInfo']=$goodInfo;
                    $result['goodSku']=$goodSku;
                    return $result;
                }else{
                    return array();
                }
            }else{
                return array();
            }
        }else{
            return array();
        }
    }

    /*
    *title 同步线上OE管理
    *@param oe OeNumber  OeNumberVechile OeVechile
    *@param Compatibility oe关联车型
    *@param userId 用户ID
    */
    public function syncOeManagement($oe,$Compatibility,$userId)
    {
        $wh['number'] = $oe['number'];
        $wh['item_id'] = $oe['item_id'];
        $oeRows = (new OeNumber())->where($wh)->find();
        if($oeRows){#更新
            $id = $oeRows['id'];
            $newOe['spu'] = $oe['spu'];
            $newOe['factory_model'] = $oe['factory_model'];
            $newOe['update_time'] = time();
            $newOe['updator_id'] = $userId;
            (new OeNumber())->where($wh)->update($newOe);
        }else{#添加
            $newOe=$wh;
            $newOe['spu'] = $oe['spu'];
            $newOe['factory_model'] = $oe['factory_model'];
            $newOe['create_time'] = time();
            $newOe['creator_id'] = $userId;
            $id = (new OeNumber())->insertGetId($newOe);
        }

        #echo "<pre>";
        #print_r($Compatibility);die;
        $cache = Cache::store('EbayOe');
        if(empty($Compatibility))return ['result'=>false,'message'=>'同步失败！'];
        foreach($Compatibility as $k => $comp){
            $NameValueList = isset($comp['NameValueList'][0])?$comp['NameValueList']:[$comp['NameValueList']];
            $vechileTemp=[];
            foreach($NameValueList as $NameVal){
                if($NameVal['Name']=='Year') $vechileTemp['year']=$NameVal['Value'];
                if($NameVal['Name']=='Make') $vechileTemp['make']=$NameVal['Value'];
                if($NameVal['Name']=='Model') $vechileTemp['model']=$NameVal['Value'];
                if($NameVal['Name']=='Trim') $vechileTemp['trim']=$NameVal['Value'];
                if($NameVal['Name']=='Engine') $vechileTemp['engine']=$NameVal['Value'];
            }
            $cacheId = $cache->getProductCache(md5(json_encode($vechileTemp)));
            if(!$cacheId){#缓存不存在，同步数据库
                $dbVechile = (new OeVechile())->where($vechileTemp)->find();
                $dbVechile = empty($dbVechile)?[]:$dbVechile->toArray();
                if($dbVechile){#数据库已存在,加入缓存
                    $cache->setProductCache(md5(json_encode($vechileTemp)),$dbVechile['id']);
                    $compInfo[$k]['ids'] = $dbVechile['id'];
                    $compInfo[$k]['notes'] = $comp['CompatibilityNotes'];
                }else{#数据库不存在,加入数据库并加入缓存
                    $newVechileTemp = $vechileTemp;
                    $newVechileTemp['create_time'] = time();
                    $dbVechileId = (new OeVechile())->insertGetId($newVechileTemp); 
                    $cache->setProductCache(md5(json_encode($vechileTemp)),$dbVechileId);
                    $compInfo[$k]['ids'] = $dbVechileId;
                    $compInfo[$k]['notes'] = $comp['CompatibilityNotes'];
                }
            }else{#缓存存在，记录ID
                $compInfo[$k]['ids'] = $cacheId;
                $compInfo[$k]['notes'] = $comp['CompatibilityNotes']?$comp['CompatibilityNotes']:"";
            }
        }
        $this->syncCompInfoCommonService($compInfo,$id);
        return ['result'=>true,'message'=>'同步成功！'];
    }

    /*
    *title OE管理-保存
    *@param oe OeNumber  OeNumberVechile OeVechile
    *@param Compatibility oe关联车型
    *@param userId 用户ID
    */
    public function oeSaveService($oe,$Compatibility,$userId)
    {
        $wh['number'] = $oe['number'];
        $wh['item_id'] = $oe['item_id'];
        $oeNumber = (new OeNumber())->where($wh)->find();
        if($oeNumber){#已存在
            return ['result'=>false,'message'=>'OE ID或者ItemId已经存在！'];
        }else{#
            $newOe = $oe;
            $newOe['creator_id'] = $userId;
            $newOe['create_time'] = time();
            $oeId = (new OeNumber())->insertGetId($newOe);
            $compInfo = $this->syncCompatibilityCommonService($Compatibility);
            $this->syncCompInfoCommonService($compInfo,$oeId);
        }
        return ['result'=>true,'message'=>'保存成功！','id'=>$oeId];
    }

    /*
    *title OE管理-编辑
    *@param oe OeNumber  OeNumberVechile OeVechile
    *@param Compatibility oe关联车型
    *@param userId 用户ID
    */
    public function oeUpdateService($oe,$Compatibility,$userId)
    {
        $wh['id'] = $oe['id'];
        $oeNumber = (new OeNumber())->where($wh)->find();
        $oeNumber = empty($oeNumber)?[]:$oeNumber->toArray();
        if(!$oeNumber){
            return ['result'=>false,'message'=>'该记录不存在！'];
        }else{#更新
            $upOe['spu'] = $oe['spu'];
            $upOe['factory_model'] = $oe['factory_model'];
            $upOe['updator_id'] = $userId;
            $upOe['update_time'] = time();
            (new OeNumber())->where($wh)->update($upOe);
            $compInfo = $this->syncCompatibilityCommonService($Compatibility);
            $this->syncCompInfoCommonService($compInfo,$oe['id']);
        }
        return ['result'=>true,'message'=>'同步成功！'];
    }

    /*
    *title OE管理-编辑
    *@param oeId 
    */
    public function oeRemoveService($oeIds)
    {   
        $wh['id'] = ['in',$oeIds];
        $whNum['oe_number_id'] = ['in',$oeIds];
        (new OeNumber())->where($wh)->delete();
        (new OeNumberVechile())->where($whNum)->delete();
        return ['result'=>true,'message'=>'删除成功！'];
    }

    /*
    *title OE管理-列表
    *@param oe 
    *@param start 
    *@param size 
    *@param userId 
    */
    public function oeListService($oe,$start,$size,$userId)
    {
        $wh=[];
        if($oe['item_id'])$wh['item_id'] = ['like','%'.$oe['item_id'].'%'];
        if($oe['number'])$wh['number'] = ['like','%'.$oe['number'].'%'];
        if($oe['spu'])$wh['spu'] = ['like','%'.$oe['spu'].'%'];
        if($oe['factory_model'])$wh['factory_model'] = ['like','%'.$oe['factory_model'].'%'];
        $lists = (new OeNumber())->where($wh)->limit($start,$size)->order("create_time desc")->select();
        $count = (new OeNumber())->where($wh)->count();
        $data = [];
        foreach($lists as $list){
            $data[] = $list->toArray();
        }
        return ['result'=>true,'rows'=>$data,'count'=>$count];
    }

    /*
    *title OE管理-编辑
    *@param oeId 
    *@param userId 
    */
    public function oeEditService($oeId,$userId)
    {
        $cache = Cache::store('EbayOe');
        $oeInfo = (new OeNumber())->where(['id'=>$oeId])->find();
        #$oeNumberVechile = (new oeNumberVechile())->where(['oe_number_id'=>$oeId])->select();
        $oeVechile = (new oeNumberVechile())->alias('oenv')->join('oe_vechile ve','oenv.oe_vechile_id=ve.id','LEFT')
        ->where(['oenv.oe_number_id'=>$oeInfo['id']])->select();
        // $oeVechile = [];
        // foreach($oeNumberVechile as $k => $oeNum){
        //     $temp = $cache->getProductDataCache($oeNum['oe_vechile_id']);
        //     if(!empty($temp)){
        //         $temp['notes'] = $oeNum['notes'];
        //         $oeVechile[] = $temp;
        //     }else{
        //         $dbTemp = (new OeVechile())->where(['id'=>$oeNum['oe_vechile_id']])->find()->toArray();
        //         $dbTemp = empty($dbTemp)?[]:$dbTemp;
        //         $dbTemp['notes'] = $oeNum['notes'];
        //         $oeVechile[] = $dbTemp;
        //     }
        // }
        $oeInfo['oe_vechiles'] = $oeVechile;
        return ['result'=>true,'rows'=>$oeInfo];
    }

    /*
    *title OE管理-获取车型信息
    *@param make 
    *@param userId 
    */
    public function oeVechileService($wh,$userId,$name)
    {
        $fields =array(
            'make'=>'model',
            'model'=>'year',
            'year'=>'trim',
            'trim'=>'engine'
        );
        $vechiles = (new OeVechile())->where($wh)->distinct(true)->field($fields[$name])->select();
        $newVechiles = [];
        foreach($vechiles as $vech){
            $newVechiles[] = $vech->toArray();
        }
        return ['result'=>true,'rows'=>$newVechiles];
    }

    /*
    *title OE管理-获取品牌信息
    *@param userId 
    */
    public function oeMakesService($userId)
    {
        $makes = (new OeVechile())->distinct(true)->field('make')->select();
        $newMakes = [];
        foreach($makes as $make){
            $newMake = $make->toArray();
            $newMakes[] = $newMake;
        }
        return ['result'=>true,'rows'=>$newMakes];
    }

    /*
    *title OE管理-同步OE通用方法
    *@param compInfo 
    *@param oeId 
    */
    public function syncCompatibilityCommonService($Compatibility=[])
    {
        if(!empty($Compatibility)){
            $cache = Cache::store('EbayOe');
            foreach($Compatibility as $k => $comp){
                $vechileTemp['year'] = $comp['year'];
                $vechileTemp['make'] = $comp['make'];
                $vechileTemp['model'] = $comp['model'];
                $vechileTemp['trim'] = $comp['trim'];
                $vechileTemp['engine'] = $comp['engine'];
                $cacheId = $cache->getProductCache(md5(json_encode($vechileTemp)));
                if(!$cacheId){#缓存不存在，同步数据库
                    $dbVechile = (new OeVechile())->where($vechileTemp)->find();
                    $dbVechile = empty($dbVechile)?[]:$dbVechile->toArray();
                    if($dbVechile){#数据库已存在,加入缓存
                        $cache->setProductCache(md5(json_encode($vechileTemp)),$dbVechile['id']);
                        $cache->setProductDataCache($dbVechile['id'],$dbVechile);
                        $compInfo[$k]['ids'] = $dbVechile['id'];
                        $compInfo[$k]['notes'] = $comp['notes'];
                    }else{#数据库不存在,加入数据库并加入缓存
                        $newVechileTemp = $vechileTemp;
                        $newVechileTemp['create_time'] = time();
                        $dbVechileId = (new OeVechile())->insertGetId($newVechileTemp); 
                        $cache->setProductCache(md5(json_encode($vechileTemp)),$dbVechileId);
                        $newVechileTemp['id'] = $dbVechileId;
                        $cache->setProductDataCache($dbVechileId,$newVechileTemp);
                        $compInfo[$k]['ids'] = $dbVechileId;
                        $compInfo[$k]['notes'] = $comp['notes'];
                    }
                }else{#缓存存在，记录ID
                    $compInfo[$k]['ids'] = $cacheId;
                    $compInfo[$k]['notes'] = $comp['notes'];
                }
            }
            return $compInfo;
        }else{
            return [];
        }
    }

    /*
    *title OE管理-oe模板合并
    *@param oe 
    *@param oeIds 
    *@param userId
    */
    public function oeModelMergeService($oe,$oeIds,$userId)
    {
        try{
            $oeNmberMod = new OeNumber();
            $oeNmVecMod = new OeNumberVechile();
            $oeVecMod = new OeVechile();
            $wh['number'] = $oe['number'];
            $wh['item_id'] = $oe['item_id'];
            $oeNumber = $oeNmberMod->where($wh)->find();
            if($oeNumber){#已存在
                return ['result'=>false,'message'=>'OE ID或者ItemId已经存在！'];
            }else{
                Db::startTrans();#开启事物
                $newOe = $oe;
                $newOe['creator_id'] = $userId;
                $newOe['create_time'] = time();
                $oeId = (new OeNumber())->insertGetId($newOe);
                $compInfoIds = [];
                $notes = [];
                foreach($oeIds as $k => $id){
                    $compInfoTemp = $oeNmVecMod->where(['oe_number_id'=>$id])->select();
                    foreach($compInfoTemp as $comp){
                        if(!in_array($comp['oe_vechile_id'],$compInfoIds)){
                            $compInfoIds[] = $comp['oe_vechile_id'];
                            $notes[] = $comp['notes'];
                        }
                    }
                    unset($compInfoTemp);
                }
                $compInfo = [];
                if($compInfoIds){
                    foreach($compInfoIds as $k2 => $vechileId){
                        $compInfo[$k2]['oe_vechile_id'] = $vechileId;
                        $compInfo[$k2]['oe_number_id'] = $oeId;
                        $compInfo[$k2]['notes'] = isset($notes[$k2])?$notes[$k2]:"";
                        $compInfo[$k2]['create_time'] = time();
                    }
                    $oeNmVecMod->saveAll($compInfo);
                }
                Db::commit();
            }
            return ['result'=>true,'message'=>'保存成功！','id'=>$oeId];
        }catch(Exception $e){
            Db::rollback();
            throw new Exception($e->getFile()."|".$e->getLine()."|".$e->getMessage());
        }
    }

    /*
    *title OE管理-同步兼容信息通用方法
    *@param compInfo 
    *@param oeId 
    */
    public function syncCompInfoCommonService($compInfo=[],$oeId)
    {
        if(!empty($compInfo)){
            (new OeNumberVechile())->where(['oe_number_id'=>$oeId])->delete();
            $oeNmberVechile = [];
            foreach($compInfo as $k => $comp){
                $oeNmberVechile[$k]['oe_number_id'] = $oeId;
                $oeNmberVechile[$k]['oe_vechile_id'] = $comp['ids'];
                $oeNmberVechile[$k]['notes'] = $comp['notes'];
                $oeNmberVechile[$k]['create_time'] = time();
            }
            (new OeNumberVechile())->saveAll($oeNmberVechile);
        }
    }
}