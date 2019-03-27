<?php
namespace app\publish\service;

use app\common\cache\Cache;
use app\common\model\amazon\AmazonAccount;
use app\common\model\amazon\AmazonCategory;
use app\common\model\amazon\AmazonGoodsTag;
use app\common\model\amazon\AmazonPublishProduct;
use app\common\model\amazon\AmazonPublishProductDetail;
use app\common\model\amazon\AmazonPublishProductSubmission;
use app\common\model\amazon\AmazonPublishTask;
use app\common\model\amazon\AmazonXsdTemplate;
use app\common\model\amazon\AmazonPublishDoc as AmazonPublishDocModel;
use app\common\model\ChannelProportion;
use app\common\model\Goods;
use app\common\model\GoodsLang;
use app\common\model\GoodsTortDescription;
use app\common\model\Lang;
use app\goods\service\CategoryHelp;
use app\index\service\ChannelConfig;
use app\publish\queue\AmazonPublishImageQueuer;
use app\publish\queue\AmazonPublishPriceQueuer;
use app\publish\queue\AmazonPublishQuantityQueuer;
use app\publish\queue\AmazonPublishRelationQueuer;
use app\publish\service\AmazonXsdTemplate as AmazonXsdTemplateService;
use app\common\model\DepartmentUserMap;
use app\common\model\GoodsSku;
use app\common\model\RoleUser;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\common\service\Filter;
use app\common\service\UniqueQueuer;
use app\common\traits\User;
use app\goods\service\GoodsHelp;
use app\index\service\DownloadFileService;
use app\publish\filter\AmazonDepartmentFilter;
use app\publish\filter\AmazonFilter;
use app\publish\queue\AmazonPublishProductQueuer;
use think\Db;
use app\goods\service\GoodsImage;
use app\common\model\GoodsPublishMap;
use app\common\model\ChannelUserAccountMap;
use app\publish\service\AmazonCategoryXsdConfig;
use app\common\model\Department as DepartmentModel;
use think\Exception;
use app\common\traits\FilterHelp;
use Waimao\AmazonMws\AmazonConfig;
use app\common\model\amazon\AmazonHeelSaleLog;

class AmazonPublishService
{
    use User;

    private $detailModel = null;

    protected $lang = 'zh';

    public function getWaitPublishGoods($params, $page, $pageSize, $fields = "*")
    {
        $where = [];
        $where['m.channel'] = ['eq', ChannelAccountConst::channel_amazon];
        $where['m.platform_sale'] = 1;
        $where['g.sales_status'] = ['<>', 2];   //在售产品；

        //关连表；
        $join = [];
        $join[] = ['goods g', 'g.id=m.goods_id'];

        if (empty($params['account']) || !is_numeric($params['account'])) {
            throw new Exception('刊登帐号id类型不正确');
        }

        if (!empty($params['developer_id'])) {
            $where['g.developer_id'] = $params['developer_id'];
        }

        if (!empty($params['lang_id'])) {
            $join[] = ['goods_lang l', 'l.goods_id=m.goods_id'];
            $where['l.lang_id'] = ['in', explode(',', $params['lang_id'])];
        }
        $help = new CategoryHelp();
        if (!empty($params['category_id'])) {
            $where['g.category_id'] = ['IN', array_merge([$params['category_id']], (array)$help->getSubIds($params['category_id']))];
        }

        $tagModel = new AmazonGoodsTag();
        //附加条件；
        $additional = '';
        if (!empty($params['snType']) && !empty($params['snText'])) {
            switch ($params['snType']) {
                case 'spu':
                    $where['m.spu'] = ['in', explode(',', $params['snText'])];
                    break;
                case 'sku':
                    $goods_ids = GoodsSku::where(['sku' => ['in', explode(',', $params['snText'])]])->column('goods_id');
                    $where['g.id'] = ['in', $goods_ids];
                    break;
                case 'name':
                    $where['g.name'] = array('like', '%' . $params['snText'] . '%');
                    break;
                default:
                    throw new Exception('未知搜索参数类别');
            }
        } else {
            if (isset($params['tag_id']) && $params['tag_id'] !== '') {
                $join[] = ['amazon_goods_tag gt', 'gt.goods_id=g.id'];
                $where['gt.tag_id'] = $params['tag_id'];
            } else {
                $additional = 'g.publish_time<'. (strtotime(date('Y-m-d')) - 86400);

                $taskWhere['create_time'] = ['BETWEEN', [strtotime(date('Y-m-d')), strtotime(date('Y-m-d')) + 86399]];
                $additionalGoodsIds = $tagModel->where($taskWhere)->column('goods_id');

                if (!empty($additionalGoodsIds)) {
                    $additional = $additional. ' OR g.id IN ('. implode(',', $additionalGoodsIds). ')';
                }
            }
        }

        $map = '`publish_status` IS NULL OR NOT JSON_CONTAINS(publish_status, \'' . json_encode([$params['account']]) . '\')';

        $model = new GoodsPublishMap;
        $count = $model->where($map)->alias('m')->join($join)->group('g.id')->where($where)->where($additional)->count('m.id');

        $data = $model->where($map)->alias('m')->join($join)->group('g.id')
            ->field('g.id,g.category_id,m.spu,thumb,name,publish_time')
            ->order('publish_time desc')
            ->where($where)
            ->where($additional)
            ->page($page, $pageSize)
            ->select();

        $goods_ids = [];
        $new_datas = [];
        foreach ($data as $k => $d) {
            $goods_ids[] = $d['id'];
            $tem = $d->toArray();
            $tem['goods_id'] = $d['id'];
            $tem['thumb'] = GoodsImage::getThumbPath($tem['thumb'], 200, 200);
            $tem['category_name'] = $help->getCategoryNameById($tem['category_id'], ($this->lang == 'zh' ? 1 : 2));
            $new_datas[] = $tem;
        }

        $getLang = Cache::store('Lang')->getLang();
        $langArr = array_combine(array_column($getLang, 'id'), array_column($getLang, 'name'));
        $langs_zh = GoodsLang::where(['goods_id' => ['in', $goods_ids], 'lang_id' => 2])->column('title', 'goods_id');
        $langs = GoodsLang::where(['goods_id' => ['in', $goods_ids]])->field('goods_id,lang_id')->select();
        $torts = GoodsTortDescription::where(['goods_id' => ['in', $goods_ids]])->group('goods_id')->field('goods_id')->column('goods_id');

        $taskServ = new AmazonPublishTaskService();
        $tags = $taskServ->getTagsByGoodsIds($goods_ids);

        //packing_en_name
        foreach ($new_datas as $k => &$d) {
            $d['packing_en_name'] = $langs_zh[$d['goods_id']] ?? '';
            $d['langs'] = [];
            foreach ($langs as $key => $val) {
                if ($d['goods_id'] == $val['goods_id']) {
                    $d['langs'][] = $langArr[$val['lang_id']] ?? '-';
                    unset($langs[$key]);
                }
            }
            $d['is_goods_tort'] = (int)in_array($d['goods_id'], $torts);
            $d['tag'] = $tags[$d['goods_id']] ?? '-';
        }
        unset($d);

        return ['count' => $count, 'page' => $page, 'pageSize' => $pageSize, 'data' => $new_datas];
    }

    /**
     * 统计未刊登商品数量
     * @param type $where
     */
    public function getWaitPublishGoodsCount($where)
    {
        return $this->goodsPublishMapModel->alias('p')->join('goods g ', 'p.goods_id=g.id', 'LEFT')->join('category c', 'g.category_id=c.id', 'LEFT')
            ->where($where)->count();
    }

    /**
     * amazon待刊登列表查询条件
     * @param type $params
     * @return string
     */
    public function getWaitPublishGoodsWhere($params)
    {
        $where = [];
        $where['p.status'] = ['eq', 1];
        $where['p.channel'] = ['eq', 1];
        $where['p.platform_sale'] = ['eq', 1];
        $where['p.publish_status'] = ['eq', 0];
        if (isset($params['snType']) && $params['snType'] == 'spu' && $params['snText']) {
            $where['p.' . $params['snType']] = array('eq', $params['snText']);
        }

        if (isset($params['snType']) && $params['snType'] == 'id' && $params['snText']) {
            $where['g.id'] = array('eq', $params['snText']);
        }

        if (isset($params['snType']) && $params['snType'] == 'name' && $params['snText']) {
            $where['g.' . $params['snType']] = array('like', '%' . $params['snText'] . '%');
        }

        if (isset($params['snType']) && $params['snType'] == 'alias' && $params['snText']) {
            $where['g.' . $params['snType']] = array('like', '%' . $params['snText'] . '%');
        }

        if (isset($params['snType']) && $params['snType'] == 'keywords' && $params['snText']) {
            $where['g.' . $params['snType']] = array('like', '%' . $params['snText'] . '%');
        }

        //分类名
        if (isset($params['snType']) && $params['snType'] == 'cname' && $params['snText']) {
            $where['c.name'] = array('like', '%' . $params['snText'] . '%');
        }

        //站点
        if (isset($params['site']) && is_string($params['site']) && $params['site']) {
            $where['site_publish_status$.' . $params['site']] = ['eq', 0];
        }
        return $where;
    }


    /**
     * 通过站点和仓库类型拿帐号列表
     * @param $site
     * @param $warehouse_type
     * @return array
     */
    public function getAccount($site, $warehouse_type)
    {
        $join = [];
        $field = 'channel_id,account_id,seller_id,customer_id,code,account_name,b.site';
        $join[] = ['amazon_account b', 'c.account_id = b.id'];
        $join[] = ['user u', 'u.id = c.seller_id'];

        if (!empty($warehouse_type)) {
            $where['warehouse_type'] = ['=', $warehouse_type];
        }

        if (!empty($site)) {
            if (is_numeric($site)) {
                $site = AmazonCategoryXsdConfig::getSiteByNum($site);
            }
            $where['b.site'] = $site;
        }

        $where['c.channel_id'] = 2;

        //if (!$this->isAdmin()) {
        //    //权限设置里的数据；
        //    $filterAccounts = (new AmazonFilter([]))->generate();
        //    $departmentAccounts = (new AmazonDepartmentFilter([]))->generate();
        //    $filters = array_unique(array_merge($filterAccounts, $departmentAccounts));
        //
        //    $where['c.account_id'] = ['in', $filters];
        //}

        if (!$this->isAdmin()) {
            $filterAccounts = $departmentAccounts = [0];

            $filterBol = false;
            $accountFilter = new Filter(AmazonFilter::class, true);
            if ($accountFilter->filterIsEffective()) {
                $filterBol = true;
                $filterAccounts = $accountFilter->getFilterContent();
            }

            $departmentFilter = new Filter(AmazonDepartmentFilter::class, true);
            if ($departmentFilter->filterIsEffective()) {
                $filterBol = true;
                $departmentAccounts = $departmentFilter->getFilterContent();
            }
            unset($accountFilter, $departmentFilter);

            //权限设置里的数据；
            if ($filterBol) {
                $filters = array_unique(array_merge($filterAccounts, $departmentAccounts));
                $where['c.account_id'] = ['in', $filters];
            }
        }

        //$where['b.status'] = ['=', 1];
        $field .= ',u.realname';

        $channelUserAccountMapModel = new ChannelUserAccountMap();
        $memberList = $channelUserAccountMapModel->alias('c')
            ->field($field)
            ->where($where)
            ->join($join)
            ->order('account_id')
            ->select();
        $list = [];
        foreach ($memberList as $data) {
            $list[] = $data->toArray();
        }

        $newList = [];
        foreach ($list as $val) {
            if (!isset($newList[$val['account_id']])) {
                $newList[$val['account_id']] = $val;
                $newList[$val['account_id']]['realname'] = strval($newList[$val['account_id']]['realname']);
            } else if (!empty($newList[$val['account_id']]['realname']) && !empty($val['realname']) && $newList[$val['account_id']]['realname'] != $val['realname']) {
                $newList[$val['account_id']]['realname'] = $newList[$val['account_id']]['realname'] . ',' . strval($val['realname']);
            }
        }
        foreach ($newList as &$val) {
            $val['site_name'] = $val['site'];
            $val['site'] = AmazonCategoryXsdConfig::getBits($val['site']);
            $val['currency'] = AmazonCategoryXsdConfig::getCurrencyBySite($val['site']);
        }
        return array_values($newList);
    }

    /**
     * 把刊登中的记录更改为失败，防止记录无限卡死在刊登中;
     * @param $id
     * @return bool
     * @throws Exception
     */
    public function defeat($id)
    {
        $pmodel = new AmazonPublishProduct();
        $product = $pmodel->get($id);
        if (!$product) {
            throw new Exception('刊登记录不存在');
        }
        if ($product['publish_status'] == AmazonPublishConfig::PUBLISH_STATUS_FINISH) {
            throw new Exception('禁止更改刊登中的记录状态为成功的产品');
        }
        if (!$this->isAdmin()) {
            $filterAccounts = (new AmazonFilter([]))->generate();
            if ($product['account_id'] == 0 || !in_array($product['account_id'], $filterAccounts)) {
                throw new Exception('没有更改刊登记录状态的权限');
            }
        }

        $update['publish_status'] = AmazonPublishConfig::PUBLISH_STATUS_ERROR;
        if ($product['product_status'] == 1) {
            $update['product_status'] = 0;
        }
        if ($product['relation_status'] == 1) {
            $update['relation_status'] = 0;
        }
        if ($product['quantity_status'] == 1) {
            $update['quantity_status'] = 0;
        }
        if ($product['image_status'] == 1) {
            $update['image_status'] = 0;
        }
        if ($product['price_status'] == 1) {
            $update['price_status'] = 0;
        }
        $product->save($update);

        //既然是强制失败，那么应该把那些提交的submissionId标为失效；
        //$this->defeatSubmissionId($id);
        return true;
    }


    /**
     * 把当前刊登记录的所有还没获取的submissionId改为失败
     * @param $product_id
     */
    private function defeatSubmissionId($product_id)
    {
        $submissionModel = new AmazonPublishProductSubmission();
        $oldSubmissionIds = $submissionModel->where(['product_id' => $product_id, 'type' => 1, 'status' => 0])->column('id');
        if (!empty($oldSubmissionId)) {
            $submissionModel->update(['status' => 9], ['id' => ['in', $oldSubmissionIds]]);
        }
    }

    /**
     * 过滤用户
     * Descript: 步骤：
     *  1.找到当前用户，
     *  2.根据当前用户去查找所属部门且是领导的部门ID；
     *  3.找出
     */
    public function filterAccount()
    {
        //获取操作人信息
        $user = Common::getUserInfo(request());
        $uid = $user['user_id'];
        if ($uid == 1) {
            return true;
        }

        $role = RoleUser::where('user_id', $uid)->column('role_id');
        if (in_array(1, $role)) {
            return true;
        }
        //查看用户所在部门；
        $departmentUserMapModel = new DepartmentUserMap();
        $deIds = $departmentUserMapModel->where(['user_id' => $uid, 'is_leader' => 1])->column('department_id');

        //不是领导,直接返回本人ID；
        if (empty($deIds)) {
            return [$uid];
        }

        $uids = [$uid];
        foreach ($deIds as $did) {
            if (in_array($did, [
                4,  //超级管理员
                2,  //董事会
            ])) {
                return true;
            }
        }
        foreach ($deIds as $did) {
            $tmpUids = $this->getUserByDepartmentId($did, $deIds);
            $uids = array_merge($uids, $tmpUids);
        }
        $uids = array_unique($uids);
        return $uids;
    }

    /**
     * 找出该部门下面的所有人员；
     * @param int $department_id 部门ID；
     * @return array
     */
    public function getUserByDepartmentId($department_id, $extra)
    {
        $uids = DepartmentUserMap::where(['department_id' => $department_id])->column('user_id');
        $departments = DepartmentModel::where(['pid' => $department_id])->select();

        foreach ($departments as $d) {
            //排除；
            if (in_array($d['id'], $extra)) {
                continue;
            }
            $tmpUids = $this->getUserByDepartmentId($d['id'], $extra);
            $uids = array_merge($uids, $tmpUids);
        }
        return $uids;
    }

    public function getBasicField($getArr = true)
    {
        $field = [
            'ItemType' => [
                'name' => 'Item Type Keyword',
                'tree' => '/Product/DescriptionData/ItemType',
                "select" => 0,
                "require" => 0,
                "maxLength" => 500,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'RecommendedBrowseNode' => [
                'name' => 'RecommendedBrowseNode',
                'tree' => 'Product/DescriptionData/RecommendedBrowseNode',
                "select" => 0,
                "require" => 0,
                "maxLength" => 30,
                "minLength" => 0,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'Department' => [
                'name' => 'Department',
                'tree' => '/Product/ProductData/category/Department',
                "select" => 0,
                "require" => 0,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'Spu' => [
                'name' => '平台父SKU',
                'tree' => '',
                "select" => 0,
                "require" => 1,
                "maxLength" => 40,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'Brand' => [
                'name' => 'Brand Name',
                'tree' => '/Product/DescriptionData/Brand',
                "select" => 0,
                "require" => 1,
                "maxLength" => 100,
                "minLength" => 0,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'Timer' => [
                'name' => $this->lang == 'zh' ? '定时刊登' : 'Timer',
                'tree' => '',
                "select" => 0,
                "require" => 0,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'warehouse_id' => [
                'name' => $this->lang == 'zh' ? '发货仓库' : 'Warehouse',
                'tree' => '',
                "select" => 1,
                "require" => 1,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'IsVirtualSend' => [
                'name' => $this->lang == 'zh' ? '是否虚拟仓发货' : 'Whether virtual warehouse delivery',
                'tree' => '',
                "select" => 0,
                "radio" => 1,
                "require" => 0,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => [0, 1],
                "totalDigits" => "",
                'value' => 0,
            ],
            'VariationTheme' => [
                'name' => 'VariationTheme',
                'tree' => '/Product/ProductData/category/VariationTheme',
                "select" => 1,
                "require" => 0,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'SaveMap' => [
                'name' => 'SaveMap',
                'tree' => '',
                "select" => 1,
                "require" => 1,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => [0, 1],
                "totalDigits" => "",
                'value' => 1,
            ]
        ];
        if ($getArr) {
            $list = [];
            foreach ($field as $key => $val) {
                $val['key'] = $key;
                $list[] = $val;
            }
            return $list;
        }
        return $field;
    }

    public function getDescriptField($getArr = true)
    {
        $field = [
            'SKU' => [
                'name' => '平台SKU',
                'tree' => '/Product/SKU',
                "select" => 0,
                "require" => 1,
                "maxLength" => 200,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'Title' => [
                'name' => $this->lang == 'zh' ? '刊登标题' : 'Title',
                'tree' => '/Product/DescriptionData/Title',
                "select" => 0,
                "require" => 1,
                "maxLength" => 200,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'SearchTerms' => [
                'name' => 'Search Terms',
                'tree' => '/Product/DescriptionData/SearchTerms',
                "select" => 0,
                "require" => 0,
                "maxLength" => 200,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'BulletPoint' => [
                'name' => 'Bullet-Point',
                'tree' => '/Product/DescriptionData/BulletPoint',
                "select" => 0,
                "require" => 0,
                "maxLength" => 500,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => ['', '', '', '', ''],
            ],
            'Description' => [
                'name' => 'Description',
                'tree' => '/Product/DescriptionData/Description',
                "select" => 0,
                "require" => 0,
                "maxLength" => 2000,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ]
        ];
        if ($getArr) {
            $list = [];
            foreach ($field as $key => $val) {
                $val['key'] = $key;
                $list[] = $val;
            }
            return $list;
        }
        return $field;
    }

    public function getSkuField($getArr = true)
    {
        $field = [
            'SKU' => [
                'name' => '平台SKU',
                'tree' => '/Product/SKU',
                'type' => 'text',
                'is_batch' => false,
                "select" => 0,
                "require" => 1,
                "maxLength" => 200,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'PublishSKU' => [
                'name' => 'SKU',
                'tree' => '/Product/SKU',
                'type' => 'input',
                'is_batch' => false,
                "select" => 0,
                "require" => 1,
                "maxLength" => 30,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'BindingGoods' => [
                'name' => 'Bundled/packaged sales',
                'tree' => '',
                'type' => 'text',
                'is_batch' => false,
                "select" => 0,
                "require" => 0,
                "maxLength" => 200,
                "minLength" => 0,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'ProductIDType' => [
                'name' => 'ProductIDType',
                'tree' => '/Product/StandardProductID/ProductID/Type',
                'type' => 'select',
                'is_batch' => true,
                "select" => 1,
                "require" => 1,
                "maxLength" => 200,
                "minLength" => 1,
                "pattern" => "",
                "option" => ['ISBN', 'UPC', 'EAN', 'ASIN', 'GTIN', 'GCID', 'PZN'],
                "totalDigits" => "",
                'value' => 'UPC',
            ],
            'ProductIdValue' => [
                'name' => 'ProductIdValue',
                'tree' => '/Product/StandardProductID/ProductID/Value',
                'type' => 'input',
                'is_batch' => false,
                "select" => 0,
                "require" => 1,
                "maxLength" => 16,
                "minLength" => 8,
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '',
            ],
            'PartNumber' => [
                'name' => 'PartNumber',
                'tree' => '/Product/DescriptionData/MfrPartNumber',
                'type' => 'input',
                'is_batch' => true,
                "select" => 0,
                "require" => 0,
                "maxLength" => 50,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'RecommendedNode' => [
                'name' => 'RecommendedNode',
                'tree' => 'Product/DescriptionData/RecommendedBrowseNode',
                'type' => 'input',
                'is_batch' => true,
                "select" => 0,
                "require" => 1,
                "maxLength" => 30,
                "minLength" => 1,
                "pattern" => "",
                "option" => "",
                "totalDigits" => "",
                'value' => '',
            ],
            'ConditionType' => [
                'name' => 'ConditionType',
                'tree' => '/Product/Condition/ConditionType',
                'type' => 'select',
                'is_batch' => true,
                "select" => 1,
                "require" => 0,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => ['New', 'UsedLikeNew', 'UsedVeryGood', 'UsedGood', 'UsedAcceptable', 'CollectibleLikeNew', 'CollectibleVeryGood', 'CollectibleGood', 'CollectibleAcceptable', 'Refurbished', 'Club'],
                "totalDigits" => "",
                'value' => 'New',
            ],
            'ConditionNote' => [
                'name' => 'ConditionNote',
                'tree' => '/Product/Condition/ConditionNote',
                'type' => 'input',
                'is_batch' => false,
                "select" => 0,
                "require" => 0,
                "maxLength" => 2000,
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '',
            ],
            'StandardPrice' => [
                'name' => 'Standard Price',
                'tree' => '/Price/StandardPrice',
                'type' => 'input',
                'is_batch' => true,
                "select" => 0,
                "require" => 1,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '',
            ],
            'SalePrice' => [
                'name' => 'Sale Price',
                'tree' => '/Price/Sale/SalePrice',
                'type' => 'input',
                'is_batch' => true,
                "select" => 0,
                "require" => 0,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '',
            ],
            'StartDate' => [
                'name' => 'Sale Start Date',
                'tree' => '/Price/Sale/StartDate',
                'type' => 'date',
                'is_batch' => true,
                "select" => 0,
                "require" => 0,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '',
            ],
            'EndDate' => [
                'name' => 'Sale End Date',
                'tree' => '/Price/Sale/EndDate',
                'type' => 'date',
                'is_batch' => true,
                "select" => 0,
                "require" => 0,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '',
            ],
            'Quantity' => [
                'name' => 'Quantity',
                'tree' => '/Inventory/Quantity',
                'type' => 'input',
                'is_batch' => true,
                "select" => 0,
                "require" => 1,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '1000',
            ],
            'SKUID' => [
                'name' => 'SKUID',
                'tree' => '',
                'type' => 'text',
                'is_batch' => true,
                "select" => 0,
                "require" => 1,
                "maxLength" => '',
                "minLength" => '',
                "pattern" => "",
                "option" => '',
                "totalDigits" => "",
                'value' => '0',
            ],
        ];
        if ($getArr) {
            $list = [];
            foreach ($field as $key => $val) {
                $val['key'] = $key;
                $list[] = $val;
            }
            return $list;
        }
        return $field;
    }

    public function getImgField($getArr = true)
    {
        $field = [
            'SpuImage' => [
                'name' => '平台父SKU 刊登图片',
                'tree' => '',
                "select" => 0,
                "require" => 1,
                "maxLength" => 200,
                "minLength" => 1,
                "pattern" => "@^(?:/.{1,199})|(?:http://.{1,193})|(?:https://.{1,192})$@",
                "option" => "",
                "totalDigits" => "",
                'value' => [],
            ],
            'SkuImage' => [
                'name' => '平台SKU 刊登图片',
                'tree' => '/ProductImage/ImageLocation',
                "select" => 0,
                "require" => 0,
                "maxLength" => 2000,
                "minLength" => 1,
                "pattern" => "@^(?:/.{1,199})|(?:http://.{1,193})|(?:https://.{1,192})$@",
                "option" => "",
                "totalDigits" => "",
                'value' => [],
            ],
        ];
        if ($getArr) {
            $list = [];
            foreach ($field as $key => $val) {
                $val['key'] = $key;
                $list[] = $val;
            }
            return $list;
        }
        return $field;
    }

    /**
     * 返回取分类和产品模板时需要过滤的字段
     * @return array
     */
    public function getFilter()
    {
        $data['basic'] = $this->getBasicField(false);
        $data['descript'] = $this->getDescriptField(false);
        $data['sku'] = $this->getSkuField(false);
        $data['img'] = $this->getImgField(false);
        $key = [];
        foreach ($data as $d) {
            foreach ($d as $k => $val) {
                $key[] = $k;
            }
        }
        return array_unique($key);
    }

    /**
     * 获取刊登的字段；
     * @return mixed
     */
    public function getPublishElement($key = true)
    {
        $data['basic'] = $this->getBasicField($key);
        $data['descript'] = $this->getDescriptField($key);
        $data['sku'] = $this->getSkuField($key);
        $data['img'] = $this->getImgField($key);
        return $data;
    }


    public function getFieldHistoryId($spu, $site)
    {
        return [];
        if (empty($site)) {
            return [];
        }
        $where = ['spu' => $spu, 'site' => $site, 'publish_status' => AmazonPublishConfig::PUBLISH_STATUS_FINISH];
        $productModel = new AmazonPublishProduct();
        $product_ids = $productModel->where($where)->order('update_time', 'desc')->limit(0, 20)->column('id');
        if (empty($product_ids)) {
            $where['site'] = ['in', [1, 2, 8, 256]];
            $product_ids = $productModel->where($where)->order('update_time', 'desc')->limit(0, 20)->column('id');
        }

        return $product_ids;
    }


    public function getFieldDocId($spu, $site)
    {
        $siteArr = array_unique([$site, 'US', 'UK', 'CA', 'AU']);

        foreach ($siteArr as $site) {
            if (!is_numeric($site)) {
                $site = AmazonCategoryXsdConfig::getBits($site);
            }
            //找出范本；
            $doc_id = (int)AmazonPublishDocModel::where(['spu' => $spu, 'site' => $site])->value('id');
            if (!empty($doc_id)) {
                break;
            }
        }

        return $doc_id;
    }

    public function getDetailVersion()
    {
        $data = (new ChannelConfig(ChannelAccountConst::channel_amazon))->getConfig('amazon_detail_version');
        if (!$data) {
            return 0;
        }
        return $data;
    }

    public function getGoodsAndSkuAttrBySpu($spu, $lang = 'en')
    {
        $result = [];
        $goodModel = new Goods();
        //先找出lang_ids
        $langs = Lang::field('id,name')->limit(30)->select();

        //先找出语言选项；
        $lang_id = 2;
        foreach ($langs as $val) {
            if (strcasecmp($val['name'], $lang) === 0) {
                $lang_id = $val['id'];
                break;
            }
        }

        $goodsInfo = $goodModel->alias('g')
            ->join(['goods_lang' => 'l'], 'g.id=l.goods_id')
            ->where(['g.spu' => $spu, 'l.lang_id' => $lang_id])
            ->field('g.id,g.name,g.category_id,g.brand_id,g.warehouse_id,l.title,l.description,selling_point')
            ->find();

        //如果当前语言没有描述，且不是英文站点的，则找英文站点的出来；
        if (empty($goodsInfo) && $lang_id != 2) {
            $goodsInfo = $goodModel->alias('g')
                ->join(['goods_lang' => 'l'], 'g.id=l.goods_id')
                ->where(['g.spu' => $spu, 'l.lang_id' => 2])
                ->field('g.id,g.name,g.category_id,g.brand_id,g.warehouse_id,l.title,l.description,selling_point')
                ->find();
        }
        //以上站点都没有，就找中文站点的
        if (empty($goodsInfo)) {
            $goodsInfo = $goodModel->alias('g')
                ->join(['goods_lang' => 'l'], 'g.id=l.goods_id', 'left')
                ->where(['g.spu' => $spu, 'l.lang_id' => 1])
                ->field('g.id,g.name,g.category_id,g.brand_id,g.warehouse_id,l.title,l.description,selling_point')
                ->find();
        }

        if (!$goodsInfo) {
            $goodsInfo = $goodModel->alias('g')
                ->join(['goods_lang' => 'l'], 'g.id=l.goods_id', 'left')
                ->where(['g.spu' => $spu])
                ->field('g.id,g.name,g.category_id,g.brand_id,g.warehouse_id,l.title,l.description,selling_point')
                ->find();
        }

        if (!$goodsInfo) {
            throw new Exception('SPU：' . $spu . '不存在');
        }

        //带出amazon五点；
        $point = ['', '', '', '', ''];
        $selling_poing = json_decode($goodsInfo->selling_point, true);
        if (is_array($selling_poing)) {
            foreach ($point as $key => $val) {
                $point[$key] = $selling_poing['amazon_point_' . ($key + 1)] ?? '';
            }
        }
        $help = new CategoryHelp();
        $goodsHelp = new GoodsHelp();
        $result['goods_id'] = $goodsInfo->id;
        $result['goods_name'] = (string)$goodsInfo->name;
        $result['goods_title'] = (string)$goodsInfo->title;
        $result['category_name'] = $goodsHelp->mapCategory($goodsInfo->category_id);
        $result['category_name'] = $help->getCategoryNameById($goodsInfo->category_id, ($this->lang == 'zh' ? 1 : 2));
        $result['brand'] = $this->getBrandById($goodsInfo->brand_id);
        $result['description'] = (string)$goodsInfo->description;
        $result['selling_point'] = $point;
        $result['warehouse_id'] = $goodsInfo->warehouse_id;
        $result['sku_list'] = [];
        $aSku = GoodsSku::where(['goods_id' => $goodsInfo->id, 'status' => ['<>', 2]])->select();
        foreach ($aSku as $v) {
            $attr = json_decode($v['sku_attributes'], true);
            $aAttr = $goodsHelp->getAttrbuteInfoBySkuAttributes($attr, $goodsInfo->id);
            $row = [];
            $row['sku_id'] = $v['id'];
            $row['sku'] = $v['sku'];
            $row['attr'] = $aAttr;
            $result['sku_list'][] = $row;
        }
        return $result;
    }


    /**
     * 获取产品品牌
     * @param int $id
     * @return string
     */
    private function getBrandById($id)
    {
        $lists = Cache::store('brand')->getBrand();
        foreach ($lists as $list) {
            if ($list['id'] == $id) {
                return $list['name'];
            }
        }
        return '';
    }


    public function getField($params)
    {
        $spu = $params['spu'];

        $account = [];
        if (!empty($params['account_id'])) {
            $account = Cache::store('AmazonAccount')->getAccount($params['account_id']);
        }
        if (empty($params['site'])) {
            if (empty($account)) {
                if ($this->lang == 'zh') {
                    throw new Exception('站点参数为空，帐号不存在，刊登错误');
                } else {
                    throw new Exception('The site parameter is empty and the account does not exist');
                }
            }
            $site_text = $account['site'];
            $site = AmazonCategoryXsdConfig::getBits($site_text);
        } else {
            $site = $params['site'];
            $site_text = AmazonCategoryXsdConfig::getSiteByNum($site);
        }

        $lang = AmazonCategoryXsdConfig::getLangCodeBySite($site);

        //找出对应站点的语言
        $goodsInfo = $this->getGoodsAndSkuAttrBySpu($spu, $lang);
        //$currency = AmazonCategoryXsdConfig::getCurrencyBySite($site);

        $taskDetail = [];
        if (!empty($account)) {
            $taskDetail = (new AmazonPublishTaskService())->taskDetail($goodsInfo['goods_id'], $account['id'], 'type,profit');
        }

        $user = Common::getUserInfo();
        //用来装返回的字段；
        $el = [];

        //头部信息；
        $el['header'] = array(
            'spu' => $spu,
            'account_id' => $params['account_id'] ?? 0,
            'code' => $account['code'] ?? '',
            'site' => $site,
            'site_text' => $site_text,
            'detail_version' => $this->getDetailVersion(),
            'goods_id' => $goodsInfo['goods_id'],
            'doc_id' => $this->getFieldDocId($spu, $site),
            'history_id' => $this->getFieldHistoryId($spu, $site),
            'goods_name' => $goodsInfo['goods_name'],
            'category_name' => $goodsInfo['category_name'],
            'brand' => $goodsInfo['brand'] ? $goodsInfo['brand'] : '',
            'profit' => (!empty($taskDetail['type']) && $taskDetail['type'] == 2) ? $taskDetail['profit'] : 0,
            'seller_name' => $user['realname'] ?? '-',
        );

        //其础信息；
        $el['basic'] = [];
        $el_basic = $this->getBasicField(false);
        $el_basic['Spu']['value'] = $spu;
        $el_basic['Brand']['value'] = $goodsInfo['brand'];
        //仓库默认2；
        $el_basic['warehouse_id']['value'] = $goodsInfo['warehouse_id'] ?? 2;
        foreach ($el_basic as $key => $val) {
            $val['key'] = $key;
            $el['basic'][] = $val;
        }

        $data_descript = $this->getDescriptField();

        //sku部分；
        $data_sku = [];
        $tmp_sku = $this->getSkuField();
        foreach ($tmp_sku as $val) {
            /*因为同时刊登几个帐号需求，这里不再显示货币，由前端自动添加
             * if (strpos($val['name'], 'Price') !== false) {
                $val['name'] = $val['name'] . '[' . $currency . ']';
            }*/
            $data_sku[$val['name']] = $val;
        }

        //图片部分
        $data_img = $this->getImgField();

        //图片信息；
        $imageModel = new GoodsImage();
        $images = $imageModel->getLists($goodsInfo['goods_id'], '');

        //变体为空，禁止刊登
        if (empty($goodsInfo['sku_list'])) {
            if ($this->lang == 'zh') {
                throw new Exception('产品变体数据为空，不可以刊登');
            } else {
                throw new Exception('The product variant data is empty and cannot be published');
            }
        }

        $skuList = $this->stripAttrZhValue($goodsInfo['sku_list']);
        $count = count($skuList);

        //加上descript 和 sku 模板；
        for ($i = 0; $i <= $count; $i++) {
            if ($i == 0) {
                $tmp_descript['skuName'] = $spu;
                $tmp_descript['detail_id'] = 0;
            } else {
                $tmp_sku = $data_sku;
                $tmp_sku['平台SKU']['value'] = $skuList[$i - 1]['sku'];
                $tmp_sku['SKU']['value'] = $skuList[$i - 1]['sku'];
                $tmp_sku['Bundled/packaged sales']['value'] = $skuList[$i - 1]['sku'] . '*1';
                $tmp_sku['BindingGoods']['value'] = $skuList[$i - 1]['sku'] . '*1';
                $tmp_sku['SKUID']['value'] = $skuList[$i - 1]['sku_id'];
                $tmp_sku['PartNumber']['value'] = $this->getRandomPartNumber($skuList[$i - 1]['sku']);
                $el['sku'][] = $tmp_sku;

                $tmp_descript['skuName'] = $skuList[$i - 1]['sku'];
                $tmp_descript['detail_id'] = 0;
            }

            $tmp_descript['field'] = $data_descript;
            $tmp_descript['field'][1]['value'] = $goodsInfo['goods_title'];
            //point
            $tmp_descript['field'][3]['value'] = $goodsInfo['selling_point'];
            //description
            $tmp_descript['field'][4]['value'] = str_replace("\r", '<br />', $goodsInfo['description']);
            $el['descript'][] = $tmp_descript;
        }

        //找出主副图的数据；
        $main = $data_img[0];
        $main['data'] = [];
        foreach ($images as $val) {
            if ($val['name'] == '主图') {
                $main['data'] = $val;
                $el['img'][] = $main;
            }
        }
        $swatch = [];
        foreach ($skuList as $sku) {
            $tmp_swatch = $data_img[1];
            $tmp_swatch['name'] = '平台SKU ' . $sku['sku'] . ' 刊登图片';
            $tmp_swatch['data'] = [];
            foreach ($images as $img) {
                if ($sku['sku'] == $img['name']) {
                    $tmp_swatch['data'] = $img;
                }
            }
            if (!empty($tmp_swatch['data']['images'])) {
                $amazonImages = [];
                $allImages = [];
                foreach ($tmp_swatch['data']['images'] as $key=>$img) {
                    if ($img['channel_id'] == 0 || $img['channel_id'] == ChannelAccountConst::channel_amazon) {
                        $amazonImages[] = $img;
                    }
                    $allImages[] = $img;
                }
                $tmp_swatch['data']['images'] = $this->uniqueImages(empty($amazonImages) ? $allImages : $amazonImages);
                unset($amazonImages, $allImages);
            }

            $swatch[] = $tmp_swatch;
        }

        foreach ($swatch as $val) {
            $el['img'][] = $val;
        }

        //找出产品原属性
        $math = [];
        $variant = [];
        if (!empty($skuList)) {
            foreach ($skuList[0]['attr'] as $attr) {
                $math[] = ['label' => '参考_' . $attr['name'], 'value' => $attr['name']];
            }

            foreach ($skuList as $val) {
                foreach ($val['attr'] as $attr) {
                    $variant[$val['sku']]['参考_' . $attr['name']] = $attr['value'];
                }
            }
        }

        $newSkuData = [];
        foreach ($el['sku'] as $skudata) {
            $tmp = [];
            foreach ($skudata as $key => $data) {
                $tmp[$key] = $data;

                //在自定义SKU后面加上参考字段；
                if ($key == 'Bundled/packaged sales') {
                    foreach ($math as $val) {
                        $tmp[$val['label']]['name'] = $val['label'];
                        $tmp[$val['label']]['value'] = '';
                        $tmp[$val['label']]['type'] = 'text';
                        if (isset($variant[$skudata['平台SKU']['value']][$val['label']])) {
                            $tmp[$val['label']]['value'] = $variant[$skudata['平台SKU']['value']][$val['label']];
                        }
                    }
                }
            }
            $newSkuData[] = $tmp;
        }

        $el['sku'] = $newSkuData;
        $el['variant_option'] = $math;
        return $el;
    }


    public function uniqueImages($images)
    {
        $data = [];
        foreach ($images as $img) {
            if (empty($data[$img['unique_code']])) {
                $data[$img['unique_code']] = $img;
            }
        }

        return array_values($data);
    }


    /**
     * @param string $sku
     * @param int $len
     * @param int $type 1字母，2数字，0字母数字下划线；
     * @return string
     */
    public function getRandomPartNumber(string $sku, $len = 4, $type = 0)
    {
        switch ($type) {
            case 1:
                $str = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
                break;
            case 2:
                $str = '0123456789';
                break;
            default:
                $str = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        //重复以保证随机数每个字符每个位数都可以出现一次；
        $str = str_repeat($str, $len);
        $randomStr = substring(str_shuffle($str), 0, $len);
        return $sku.$randomStr;
    }


    /**
     * 编辑刊登记录时，获取参数；
     * @param $id
     * @param $copy 0编辑取值，1复制取值；
     */
    public function getPublishData($id, $copy = 0)
    {
        $publishModel = new AmazonPublishProduct();
        $publish = $publishModel->where(['id' => $id])->find();
        if (empty($publish)) {
            if ($this->lang == 'zh') {
                throw new Exception('未知刊登记录ID');
            } else {
                throw new Exception('Unknown publication record ID');
            }
        }
        $detailList = (new AmazonPublishProductDetail())->where(['product_id' => $id])->select();
        //编辑时；只能编辑刊登失败的数据；
        if ($copy == 0) {
            //编辑则
            if (in_array($publish['publish_status'], [0, 3, 4, 5])) {
                //                $publishModel->update(['publish_status' => 4], ['id' => $id]);
            } else {
                if ($this->lang == 'zh') {
                    throw new Exception('刊登状态：正在上传、上传成功或正在编辑的数据禁止再次编辑');
                } else {
                    throw new Exception('Publish status: Data uploading uploaded, or editing is prohibited from editing again');
                }
            }
        }
        //站点语言；
        $lang = AmazonCategoryXsdConfig::getLangCodeBySite($publish['site']);

        //查出产品
        $goodsInfo = $this->getGoodsAndSkuAttrBySpu($publish['spu'], $lang);
        $skuList = $this->stripAttrZhValue($goodsInfo['sku_list']);

        //查出图片；
        $imageModel = new GoodsImage();
        $images = $imageModel->getLists($goodsInfo['goods_id'], '');

        $goodsSkuModel = new GoodsSku();

        //用来装返回的字段；
        $el = [];

        if (!$copy) {
            $el['id'] = $publish['id'];
        } else {
            $el['id'] = 0;
        }
        $account = Cache::store('AmazonAccount')->getAccount($publish['account_id']);
        if (empty($account)) {
            if ($this->lang == 'zh') {
                throw new Exception('未知刊登帐号ID');
            } else {
                throw new Exception('Unknown publish account ID');
            }
        }
        //$currency = AmazonCategoryXsdConfig::getCurrencyBySite($account['site']);

        $el['account_id'] = $publish['account_id'];
        $el['account_code'] = $account['code'];
        $el['site'] = $publish['site'];
        $el['is_translate'] = $publish['is_translate'];
        $el['goods_id'] = $publish['goods_id'];
        $el['category_id'] = $publish['category_id'];
        $el['category_template_id'] = $publish['category_template_id'];
        $el['product_template_id'] = $publish['product_template_id'];

        $categoryModel = new AmazonCategory();
        $el['amazon_category_name'] = '';
        $el['amazon_category_name2'] = '';

        $user = Common::getUserInfo();
        //头部信息；
        $el['header'] = array(
            'spu' => $publish['spu'],
            'detail_version' => $this->getDetailVersion(),
            'goods_id' => $publish['goods_id'],
            'goods_name' => $goodsInfo['goods_name'],
            'category_name' => $goodsInfo['category_name'],
            'brand' => $publish['brand'] ? $publish['brand'] : $goodsInfo['brand'],
            'seller_name' => $user['realname'],
        );

        //其础信息；
        $el['basic'] = [];
        $el_basic = $this->getBasicField(false);
        $el_basic['ItemType']['value'] = $publish['item_type'];
        $el_basic['RecommendedBrowseNode']['value'] = '';

        $newNodeArr = [];
        if (!empty($publish['recommend_node'])) {
            $nodeArr = explode(',', $publish['recommend_node']);
            if (!empty($nodeArr[0])) {
                $category_name = $this->getAmazonCategoryName($nodeArr[0], $account['site'], $categoryModel);
                if ($category_name) {
                    $newNodeArr[] = $nodeArr[0];
                    $el['amazon_category_name'] = $nodeArr[0] . ' ->> ' . $category_name;
                }
            }
            if (!empty($nodeArr[1])) {
                $category_name = $this->getAmazonCategoryName($nodeArr[1], $account['site'], $categoryModel);
                if ($category_name) {
                    $newNodeArr[] = $nodeArr[1];
                    $el['amazon_category_name2'] = $nodeArr[1] . ' ->> ' . $category_name;
                }
            }
            if (!empty($newNodeArr)) {
                $el_basic['RecommendedBrowseNode']['value'] = implode(',', $newNodeArr);
            }
        }
        unset($categoryModel);

        $el_basic['Department']['value'] = $publish['department'];

        $el_basic['Spu']['value'] = $publish['spu'];
        $el_basic['Brand']['value'] = $publish['brand'] ? $publish['brand'] : $goodsInfo['brand'];
        $el_basic['Timer']['value'] = empty($publish['timer']) ? '' : date('Y-m-d H:i:s', $publish['timer']);
        $el_basic['IsVirtualSend']['value'] = $publish['is_virtual_send'];
        $el_basic['warehouse_id']['value'] = $publish['warehouse_id'];
        $el_basic['VariationTheme']['value'] = $publish['theme_name'];
        $el_basic['SaveMap']['value'] = $publish['save_map'];
        foreach ($el_basic as $key => $val) {
            $val['key'] = $key;
            $el['basic'][] = $val;
        }

        //descript部分；
        $el['descript'] = [];
        $data_descript = $this->getDescriptField(false);

        //sku部分；
        $el['sku'] = [];
        $data_sku = $this->getSkuField(false);

        //表里存的存片数据；
        $main_img = [];
        $variant_img = [];
        $variant_info = [];
        $variant_info_arr = [];
        //本SPU以外的sku统计
        $spuOUterSkus = [];

        //图片部分
        $data_img = $this->getImgField();
        //图片的base_url;
        $baseUrl = Cache::store('configParams')->getConfig('innerPicUrl')['value'] . DS;

        foreach ($detailList as $key => $detail) {
            $tmp_descript = $data_descript;

            $search_Terms = json_decode($detail['search_Terms'], true);
            $bullet_point = json_decode($detail['bullet_point'], true);

            $tmp_descript['SKU']['value'] = $detail['sku'];
            $tmp_descript['Title']['value'] = str_replace([$account['code'], $publish['brand']], '', $detail['title']);
            if (is_array($search_Terms)) {
                $tmp_descript['SearchTerms']['value'] = implode(';', array_filter($search_Terms));
            } else {
                $tmp_descript['SearchTerms']['value'] = $detail['search_Terms'];
            }
            $tmp_descript['BulletPoint']['value'] = [
                $bullet_point[0]?? '', $bullet_point[1]?? '', $bullet_point[2]?? '', $bullet_point[3]?? '', $bullet_point[4]?? ''
            ];
            $tmp_descript['Description']['value'] = $detail['description'];
            $new_tmp_descript = [];
            foreach ($tmp_descript as $keyd => $val) {
                $val['key'] = $keyd;
                $new_tmp_descript[] = $val;
            }

            $el['descript'][$key]['skuName'] = $detail['sku'];
            $el['descript'][$key]['detail_id'] = $detail['id'];
            $el['descript'][$key]['field'] = $new_tmp_descript;

            //sku部分；
            if ($key > 0) {
                $tmp_sku = $data_sku;
                $tmp_sku['SKU']['value'] = $detail['sku'];
                $tmp_sku['PublishSKU']['value'] = ($copy == 0) ? $detail['publish_sku'] : $detail['sku'];
                $tmp_sku['PartNumber']['value'] = ($copy == 0) ? $detail['part_number'] : '';
                $tmp_sku['SKUID']['value'] = 0;
                foreach ($skuList as $val) {
                    if ($val['sku'] == $detail['sku']) {
                        $tmp_sku['SKUID']['value'] = $val['sku_id'];
                    }
                }

                if (empty($detail['recommend_node'])) {
                    $tmp_sku['RecommendedNode']['value'] = $newNodeArr[0] ?? '';
                } else {
                    $tmp_sku['RecommendedNode']['value'] = $detail['recommend_node'];
                }

                $tmp_sku['BindingGoods']['value'] = $detail['binding_goods'];

                $tmp_sku['ProductIDType']['value'] = $detail['product_id_type'];
                $tmp_sku['ProductIdValue']['value'] = ($copy == 0) ? $detail['product_id_value'] : '';

                //在编辑的时候，如果当前产品信息已经刊登成功，不允许修改期publish_sku 和 UPC;
                if ($copy == 0 && $detail['upload_product'] == AmazonPublishConfig::DETAIL_PUBLISH_STATUS_FINISH) {
                    $tmp_sku['PublishSKU']['type'] = 'text';
                    $tmp_sku['ProductIdValue']['type'] = 'text';
                }

                $tmp_sku['ConditionType']['value'] = $detail['condition_type'];
                $tmp_sku['ConditionType']['value'] = $detail['condition_note'];
                $tmp_sku['StandardPrice']['value'] = $detail['standard_price'];

                $tmp_sku['SalePrice']['value'] = $detail['sale_price'];
                $tmp_sku['StartDate']['value'] = ($detail['sale_start_date'] != 0) ? date('Y-m-d H:i:s', $detail['sale_start_date']) : '';
                $tmp_sku['EndDate']['value'] = ($detail['sale_end_date'] != 0) ? date('Y-m-d H:i:s', $detail['sale_end_date']) : '';
                $tmp_sku['Quantity']['value'] = $detail['quantity'];
                $new_tmp_sku = [];
                foreach ($tmp_sku as $sku_key => $val) {
                    $val['key'] = $sku_key;
                    /*此处在前端加货币单位
                     * if (strpos($val['name'], 'Price') !== false) {
                        $val['name'] = $val['name'] . '[' . $currency . ']';
                    }*/
                    $new_tmp_sku[$val['name']] = $val;
                }
                $el['sku'][] = $new_tmp_sku;

                $variant_info[$detail['sku']] = empty($detail['variant_info']) ? [] : json_decode($detail['variant_info'], true);
                $temp_variant_info['skuName'] = $detail['sku'];
                $temp_variant_info['field'] = empty($detail['variant_info']) ? [] : json_decode($detail['variant_info'], true);
                $variant_info_arr[] = $temp_variant_info;
            }

            //取出图片部分；
            if ($key == 0) {
                $main_img = [
                    ['base_url' => $baseUrl, 'path' => $detail['main_image']]
                ];
                $other_image = json_decode($detail['other_image'], true);
                foreach ($other_image as $val) {
                    $main_img[] = ['base_url' => $baseUrl, 'path' => $val];
                }

                //找出主副图的数据；
                $main = $data_img[0];
                $main['data'] = [];
                foreach ($images as $val) {
                    if ($val['name'] == '主图') {
                        $main['data'] = $val;
                        $main['value'] = $main_img;
                        continue;
                    }
                }
                $el['img'][] = $main;

            } else {
                $variant_img = [];

                if ($detail['main_image'] != '') {
                    if ($detail['main_image'] != $detail['swatch_image']) {
                        $variant_img[] = ['base_url' => $baseUrl, 'path' => $detail['main_image'], 'is_default' => 1, 'is_swatch' => false];
                        if ($detail['swatch_image'] != '') {
                            $variant_img[] = ['base_url' => $baseUrl, 'path' => $detail['swatch_image'], 'is_default' => 0, 'is_swatch' => true];
                        }
                    } else {
                        $variant_img[] = ['base_url' => $baseUrl, 'path' => $detail['swatch_image'], 'is_default' => 1, 'is_swatch' => true];
                    }
                } else {
                    if ($detail['swatch_image'] != '') {
                        $variant_img[] = ['base_url' => $baseUrl, 'path' => $detail['swatch_image'], 'is_default' => 0, 'is_swatch' => true];
                    }
                }
                $other_image = json_decode($detail['other_image'], true);
                if (!empty($other_image) && is_array($other_image)) {
                    foreach ($other_image as $val) {
                        $variant_img[] = ['base_url' => $baseUrl, 'path' => $val, 'is_default' => 0, 'is_swatch' => false];
                    }
                }

                $tmp_swatch = $data_img[1];
                $tmp_swatch['name'] = '平台SKU ' . $detail['sku'] . ' 刊登图片';
                $tmp_swatch['value'] = $variant_img;
                $tmp_swatch['data'] = [];
                foreach ($images as $img) {
                    if ($detail['sku'] == $img['name']) {
                        $tmp_swatch['data'] = $img;
                        continue;
                    }
                }

                if (!empty($tmp_swatch['data']['images'])) {
                    $amazonImages = [];
                    $allImages = [];
                    foreach ($tmp_swatch['data']['images'] as $img) {
                        if ($img['channel_id'] == 0 || $img['channel_id'] == ChannelAccountConst::channel_amazon) {
                            $amazonImages[] = $img;
                        }
                        $allImages[] = $img;
                    }
                    $tmp_swatch['data']['images'] = $this->uniqueImages(empty($amazonImages) ? $allImages : $amazonImages);
                    unset($amazonImages, $allImages);
                }

                //此处判断为空，则为另外SPU的产品，另外逻辑来处理；
                if (empty($tmp_swatch['data']) && !empty($detail['sku'])) {
                    $goodsSkuData = $goodsSkuModel->where(['sku' => $detail['sku']])->find();
                    $tmp_swatch['data']['name'] = $detail['sku'];
                    $tmp_swatch['data']['baseUrl'] = $baseUrl;
                    $tmp_swatch['data']['attribute_id'] = 0;
                    $tmp_swatch['data']['value_id'] = 0;
                    $tmp_swatch['data']['sku_id'] = 0;
                    $tmp_swatch['data']['goods_id'] = 0;
                    $tmp_swatch['data']['images'] = [];
                    if (!empty($goodsSkuData)) {
                        $tmp_swatch['data']['sku_id'] = $goodsSkuData['id'];
                        $tmp_swatch['data']['goods_id'] = $goodsSkuData['goods_id'];
                        $spuOUterSkus[$detail['sku']] = $goodsSkuData->toArray();
                    }
                }

                $el['img'][] = $tmp_swatch;
            }
        }

        //找出产品原属性
        $math = [];
        $variant = [];

        if (!empty($skuList)) {
            foreach ($skuList[0]['attr'] as $attr) {
                $math[] = ['label' => '参考_' . $attr['name'], 'value' => $attr['name']];
            }

            foreach ($skuList as $val) {
                foreach ($val['attr'] as $attr) {
                    $variant[$val['sku']]['参考_' . $attr['name']] = $attr['value'];
                }
            }
        }

        //把参考属性装进sku部分；
        $newSkuData = [];
        foreach ($el['sku'] as $skudata) {
            $tmp = [];
            //把不在当前SPU的SKUID区配上
            if (!empty($spuOUterSkus[$skudata['SKU']['value']])) {
                $skudata['SKUID']['value'] = $spuOUterSkus[$skudata['SKU']['value']]['id'];
            }
            foreach ($skudata as $key => $data) {
                $tmp[$key] = $data;

                //在自定义SKU后面加上参考字段；
                if ($key == 'Bundled/packaged sales') {
                    foreach ($math as $val) {
                        $tmp[$val['label']]['name'] = $val['label'];
                        $tmp[$val['label']]['value'] = '';
                        $tmp[$val['label']]['type'] = 'text';
                        if (isset($variant[$skudata['平台SKU']['value']][$val['label']])) {
                            $tmp[$val['label']]['value'] = $variant[$skudata['平台SKU']['value']][$val['label']];
                        }
                    }
                }
            }
            $newSkuData[] = $tmp;
        }

        $el['sku'] = $newSkuData;
        $el['variant_option'] = $math;
        $el['category_template_info'] = is_string($publish['category_info']) ? json_decode($publish['category_info'], true) : $publish['category_info'];
        $el['product_template_info'] = is_string($publish['product_info']) ? json_decode($publish['product_info'], true) : $publish['product_info'];
        //对一键引用的数据做另外处理；
        if ($copy > 0) {
            foreach ($el['product_template_info'] as $key => $val) {
                if (strpos($key, 'PartNumber') !== false) {
                    $el['product_template_info'][$key] = '';
                }
            }
        }
        $el['variant_info'] = $variant_info;
        $el['variant_info_arr'] = $variant_info_arr;

        return $el;

    }

    /**
     * 把SKU产品里面的属性里面的中文去掉
     * @param $list
     * @return array
     */
    private function stripAttrZhValue($list)
    {
        if (!is_array($list)) {
            return [];
        }
        $newList = [];
        foreach ($list as $key => $val) {
            $newVal = ['sku_id' => $val['sku_id'], 'sku' => $val['sku'], 'attr' => []];
            if (!empty($val['attr'])) {
                foreach ($val['attr'] as $sku) {
                    $skuValueArr = explode('|', $sku['value']);
                    $sku['value'] = count($skuValueArr) == 2 ? $skuValueArr[1] : $skuValueArr[0];
                    $newVal['attr'][] = $sku;
                }
            }
            $newList[] = $newVal;
        }

        return $newList;
    }

    /**
     * 组装前端要的category_name
     * @param $category_id
     */
    private function getAmazonCategoryName($category_id, $site, $model)
    {
        $category = $model->where(['category_id' => $category_id, 'site' => $site])->field('category_id,name,path')->find();
        return (!empty($category['path'])) ? str_replace(',', '>>', $category['path']) : '';
    }


    /** @var array 用来存放保存详情页，变体的字段 */
    private $variantKey = [];
    private $productUnset = [];

    /**
     * 验证刊登详情发来的参数是否正则；
     * @param $data
     * @return bool
     * @throws Exception
     */
    public function checkPublishData($data, $lang)
    {
        $templateHelp = new AmazonXsdTemplateService();
        //验证分类模板参数；
        $category_template_id = $data['category_template']['id'];
        $category_template_data = $templateHelp->getAttr($category_template_id, $data['site']);

        //验证分类模板元素；
        if (empty($category_template_data)) {
            if ($lang == 'zh') {
                throw new Exception('分类模板ID不存在');
            } else {
                throw new Exception('Classification template ID does not exist');
            }
        }

        //验证分类模板元素；
        if (!empty($category_template_data['message'])) {
            if ($lang == 'zh') {
                throw new Exception($category_template_data['message']);
            } else {
                throw new Exception('The category template does not exist or is not enabled, and there is no default template');
            }
        }

        //用来装变体字段，保存变体用；
        $variantfield = [];
        //变体rule，下面验证SKU时，用来验证变体字段;
        $variantRule = [];
        //下面验证SKU数据；先找出变体字段是哪几个字段；
        $variantList = $category_template_data['variant'];
        //用来验证变体重复；
        $theme = '';

        //不存在，设为0；
        !isset($data['basic']['VariationTheme']) && $data['basic']['VariationTheme'] = 0;

        if (!empty($variantList) && empty($data['basic']['VariationTheme'])) {
            if ($lang == 'zh') {
                throw new Exception('分类模板有变体存在时，必须选择一个变体');
            } else {
                throw new Exception('When a classification template has a variant, you must select a variant');
            }
        }

        if (!empty($variantList) && !empty($data['basic']['VariationTheme'])) {
            foreach ($variantList as $variant) {
                if ($variant['id'] == $data['basic']['VariationTheme']) {
                    $theme = $variant['name'];
                    $variantfield = $variant['relation_field'];
                    break;
                }
            }
            if (!empty($variantfield)) {
                $variantfield = is_string($variantfield) ? json_decode($variantfield, true) : $variantfield;
            }

            foreach ($category_template_data['attrs'] as $val) {
                if (in_array($val['name'], $variantfield)) {
                    $variantRule[$val['name']] = $val;

                    //变体元素的属性，要加进 variantfield 数组；
                    if (!empty($val['attribute']['name'])) {
                        $variantfield[] = $val['name'] . '@' . $val['attribute']['name'];
                    }
                }
            }
        }
        //保变体键存存一下，保存Sku时用来取出键；
        $this->variantKey[$data['account_id']] = $variantfield;

        foreach ($data['category_template'] as $key => $field) {
            if ($key == 'id') {
                continue;
            }
            if (strpos($key, '@') !== false) {
                $tmpArr = explode('@', $key);
                $tmpEle = $tmpArr[0];
                $tmpAttr = $tmpArr[1];
                $attrArr = [];
                foreach ($category_template_data['attrs'] as $element) {
                    if ($element['name'] == $tmpEle) {
                        if ($element['attribute']['name'] != $tmpAttr) {
                            if ($lang == 'zh') {
                                throw new Exception('分类模板，' . $key . '参数属性名错误');
                            } else {
                                throw new Exception('Classification template,' . $key . ' Parameter property name error');
                            }
                        }
                        $attrArr = $element['attribute']['restriction'];
                    }
                }

                //填写了前面的参数，后面的属性则为必填；
                if (empty($field) && !empty($data['category_template'][$tmpEle])) {
                    if ($lang == 'zh') {
                        throw new Exception('分类模板，' . $key . '参数值不为空时，请填写或选择后面的单位值');
                    } else {
                        throw new Exception('Classification template, ' . $key . ' When the parameter value is not empty, please fill in or select the following unit value');
                    }
                }
                if (!empty($attrArr)) {
                    if (isset($attrArr[0]) && !in_array($field, $attrArr)) {
                        if ($lang == 'zh') {
                            throw new Exception('分类模板' . $key . '参数属性值：' . $field . ' 错误');
                        } else {
                            throw new Exception('Category template ' . $key . ' Parameter property value: ' . $field . ' Error');
                        }
                    } else {
                        $result = $this->checkField($field, $attrArr);
                        if ($result !== true) {
                            if ($lang == 'zh') {
                                throw new Exception('分类模板' . $key . '参数属性值：' . $field . ' 错误');
                            } else {
                                throw new Exception('Category template ' . $key . ' Parameter property value: ' . $field . ' Error');
                            }
                        }
                    }
                }
                continue;
            }
            //找出具体对应的元素和规则；
            $rule = [];
            foreach ($category_template_data['attrs'] as $element) {
                ($element['name'] == $key) && $rule = $element;
            }
            if (empty($rule)) {
                if ($lang == 'zh') {
                    throw new Exception('分类模板' . $key . '参数错误');
                } else {
                    throw new Exception('Category template ' . $key . ' Parameter error');
                }
            }

            //转化看是不是json
            if (is_string($field) && json_decode($field)) {
                $field = json_decode($field, true);
            }

            //在变体里面填了的必填项，这里就不再要求必填了；
            if (in_array($key, $variantList) && isset($rule['require']) && $rule['require'] == 1) {
                $rule['require'] = 0;
            }

            if (is_array($field)) {
                foreach ($field as $f) {
                    $result = $this->checkField($f, $rule);
                    if ($result !== true) {
                        if ($lang == 'zh') {
                            throw new Exception('分类模板，' . $key . '参数值' . $result);
                        } else {
                            throw new Exception('Category template，' . $key . ' Parameter property value ' . $result);
                        }
                    }
                }
            } else {
                $result = $this->checkField($field, $rule);
                if ($result !== true) {
                    if ($lang == 'zh') {
                        throw new Exception('分类模板，' . $key . ' 参数值' . $result);
                    } else {
                        throw new Exception('Category template，' . $key . ' Parameter value ' . $result);
                    }
                }
            }
            //必填元素如果有属性，也需要填；
            if ((!empty($rule['required']) || !empty($field)) && !empty($rule['attribute']['name'])) {
                $attrKey = $key . '@' . $rule['attribute']['name'];
                if (empty($data['category_template'][$attrKey])) {
                    if ($lang == 'zh') {
                        throw new Exception('分类模板，' . $key . '参数值为必填或有值时，请填写或选择后面的单位值');
                    } else {
                        throw new Exception('Category template，' . $key . ' When the parameter value is required or has a value, please fill in or select the following unit value');
                    }
                }
            }
        }

        $product_template_id = $data['product_template']['id'];
        $product_template_data = $templateHelp->getProductAttr($product_template_id, $data['site']);

        //验证产品模板元素；
        if (empty($product_template_data)) {
            if ($lang == 'zh') {
                throw new Exception('分类模板ID不存在');
            } else {
                throw new Exception('Classification template ID does not exist');
            }
        }

        //验证产品模板元素；
        if (!empty($product_template_data['message'])) {
            if ($lang == 'zh') {
                throw new Exception($product_template_data['message']);
            } else {
                throw new Exception('The product template does not exist or is not enabled, and there is no default template');
            }
        }
        foreach ($data['product_template'] as $key => $field) {
            if ($key == 'id') {
                continue;
            }
            if (strpos($key, '@') !== false) {
                $tmpArr = explode('@', $key);
                $tmpEle = $tmpArr[0];
                $tmpAttr = $tmpArr[1];
                $attrArr = [];
                foreach ($product_template_data['attrs'] as $element) {
                    if ($element['name'] == $tmpEle) {
                        if ($element['attribute']['name'] != $tmpAttr) {
                            if ($lang == 'zh') {
                                throw new Exception('产品模板，' . $key . '参数属性名错误');
                            } else {
                                throw new Exception('Product template,' . $key . ' Parameter property name error');
                            }
                        }
                        $attrArr = $element['attribute']['restriction'];
                    }
                }
                //填写了前面的参数，后面的属性则为必填；
                if (empty($field) && !empty($data['product_template'][$tmpEle])) {
                    if ($lang == 'zh') {
                        throw new Exception('产品模板，' . $key . '参数值不为空时，请填写或选择后面的单位值');
                    } else {
                        throw new Exception('Product template,' . $key . ' When the parameter value is not empty, please fill in or select the following unit value');
                    }
                }
                if (!empty($attrArr) && isset($attrArr[0]) && !in_array($field, $attrArr)) {
                    if ($lang == 'zh') {
                        throw new Exception('产品模板' . $key . '参数属性值：' . $field . ' 错误');
                    } else {
                        throw new Exception('Product template ' . $key . ' Parameter property value:' . $field . ' Error');
                    }
                } else {
                    $result = $this->checkField($field, $attrArr);
                    if ($result !== true) {
                        if ($lang == 'zh') {
                            throw new Exception('产品模板，' . $key . '参数属性值' . $field . ' 错误');
                        } else {
                            throw new Exception('Product template ' . $key . ' Parameter property value:' . $field . ' Error');
                        }
                    }
                }
                continue;
            }
            //找出具体对应的元素和规则；
            $rule = [];
            foreach ($product_template_data['attrs'] as $element) {
                ($element['name'] == $key) && $rule = $element;
            }

            if (empty($rule)) {
                $this->productUnset[] = $field;
                continue;
                //throw new Exception('产品模板，' . $key . '参数名错误');
            }

            if ($rule['node_tree'] == 'Product,DescriptionData,Manufacturer' && (strpos($field, '无') !== false)) {
                if ($lang == 'zh') {
                    throw new Exception('产品模板，' . $key . '参数值"' . $field . '"不正确，禁止用"无"');
                } else {
                    throw new Exception('Product template, ' . $key . ' parameter value "' . $field . '" is incorrect，prohibiting using "无"');
                }
            }

            //转化看是不是json
            if (is_string($field) && json_decode($field)) {
                $field = json_decode($field, true);
            }

            if (is_array($field)) {
                foreach ($field as $f) {
                    $result = $this->checkField($f, $rule);
                    if ($result !== true) {
                        if ($lang == 'zh') {
                            throw new Exception('产品模板，' . $key . '参数' . $result);
                        } else {
                            throw new Exception('Product template, ' . $key . ' parameter ' . $result);
                        }
                    }
                }
            } else {
                $result = $this->checkField($field, $rule);
                if ($result !== true) {
                    if ($lang == 'zh') {
                        throw new Exception('产品模板，' . $key . ' 参数 ' . $result);
                    } else {
                        throw new Exception('Product template, ' . $key . ' parameter ' . $result);
                    }
                }
            }
            //必填元素如果有属性，也需要填；
            if ((!empty($rule['required']) || !empty($field)) && !empty($rule['attribute']['name'])) {
                $attrKey = $key . '@' . $rule['attribute']['name'];
                if (empty($data['product_template'][$attrKey])) {
                    if ($lang == 'zh') {
                        throw new Exception('产品模板，' . $key . '参数值为必填或有值时，请填写或选择后面的单位值');
                    } else {
                        throw new Exception('Product template, ' . $key . ' When the parameter value is required or has a value, please fill in or select the following unit value');
                    }
                }
            }
        }

        //验证descript数据；
        $ruleArr = $this->getPublishElement(false);

        $key = 'basic';
        foreach ($data['basic'] as $k => $field) {
            if (!isset($ruleArr[$key][$k])) {
                if ($lang == 'zh') {
                    throw new Exception('基础信息，' . $k . '参数名错误');
                } else {
                    throw new Exception('Basic information,' . $k . ' Parameter name error');
                }
            }
            if (is_string($field) && json_decode($field)) {
                $field = json_decode($field, true);
            }

            if ($k == 'Brand' && (strpos($field, '无') !== false)) {
                if ($lang == 'zh') {
                    throw new Exception('基础信息，' . $k . '参数值"' . $field . '"不正确，禁止用"无"');
                } else {
                    throw new Exception('Basic information,' . $k . ' parameter value "' . $field . '" is incorrect， prohibiting using "无"');
                }
            }

            $rule = $ruleArr[$key][$k];
            if (is_array($field)) {
                foreach ($field as $f) {
                    $result = $this->checkField($f, $rule);
                    if ($result !== true) {
                        if ($lang == 'zh') {
                            throw new Exception('基础信息，' . $k . '参数值' . $result);
                        } else {
                            throw new Exception('Basic information,' . $k . ' parameter value ' . $result);
                        }
                    }
                }
            } else {
                $result = $this->checkField($field, $rule);
                if ($result !== true) {
                    if ($lang == 'zh') {
                        throw new Exception('基础信息，' . $k . '参数值' . $result);
                    } else {
                        throw new Exception('Basic information,' . $k . ' parameter value ' . $result);
                    }
                }
            }
        }
        foreach ($data['descript'] as $linezero => $arr) {
            $line = $linezero + 1;
            foreach ($arr as $k => $field) {
                //这里的SKU只是用来和sku部分的SKU相对比来排序的，不进行验证;；
                if ($k == 'SKU') {
                    continue;
                }
                if (!isset($ruleArr['descript'][$k])) {
                    continue;
                    //throw new Exception('标题与描述' . 'SKU:' . $arr['SKU'] . '页面，' . $k . ' 参数名错误');
                }
                $rule = $ruleArr['descript'][$k];
                if (is_string($field) && json_decode($field)) {
                    $field = json_decode($field, true);
                }
                if (is_array($field)) {
                    foreach ($field as $f) {
                        $result = $this->checkField($f, $rule);
                        if ($result !== true) {
                            if ($lang == 'zh') {
                                throw new Exception('标题与描述' . 'SKU:' . $arr['SKU'] . '页面，' . $k . ' 参数值' . $result);
                            } else {
                                throw new Exception('Title and description ' . 'SKU:' . $arr['SKU'] . 'Page, ' . $k . ' Parameter value ' . $result);
                            }
                        }
                    }
                } else {
                    $result = $this->checkField($field, $rule);
                    if ($result !== true) {
                        if ($lang == 'zh') {
                            throw new Exception('标题与描述' . 'SKU:' . $arr['SKU'] . '页面，' . $k . ' 参数值' . $result);
                        } else {
                            throw new Exception('Title and description ' . 'SKU:' . $arr['SKU'] . 'Page, ' . $k . ' Parameter value ' . $result);
                        }
                    }
                    //标题与描述
                    if ($k === 'Description' && !empty($field)) {
                        $result = $this->checkDescription($field, $lang);
                        if ($result !== true) {
                            if ($lang == 'zh') {
                                throw new Exception('标题与描述' . 'SKU:' . $arr['SKU'] . '页面，' . $k . ' 参数值' . $result);
                            } else {
                                throw new Exception('Title and description ' . 'SKU:' . $arr['SKU'] . 'Page, ' . $k . ' Parameter value ' . $result);
                            }
                        }
                    }
                }
            }
        }

        //        $rule = $ruleArr['img']['SpuImage'];
        //        //验证main_image图片
        //        foreach($data['img']['SpuImage'] as $linezero=>$field) {
        //            $line = $linezero + 1;
        //            $result = $this->checkField($field, $rule);
        //            if($result !== true) {
        //                throw new Exception('平台父SKU刊登图片，第 '. $line. ' 张图片'. $result);
        //            }
        //        }
        //
        //
        //$rule = $ruleArr['img']['SkuImage'];
        ////验证switch_image图片
        //foreach($data['img']['SkuImage'] as $linezero=>$field) {
        //    $line = $linezero + 1;
        //    if(is_array($field['main'])) {
        //        foreach($field['main'] as $k=>$f) {
        //            $result = $this->checkField($f, $rule);
        //            if($result !== true) {
        //                throw new Exception('平台SKU刊登图片，第 '. $line. ' 行，第'. ($k + 1). ' 张 main 图'. $result);
        //            }
        //        }
        //    } else {
        //        $result = $this->checkField($field['main'], $rule);
        //        if($result !== true) {
        //            throw new Exception('平台SKU刊登图片，第 '. $line. ' 行main图片'. $result);
        //        }
        //    }
        //
        //    if(is_array($field['swatch'])) {
        //        foreach($field['swatch'] as $k=>$f) {
        //            $result = $this->checkField($f, $rule);
        //            if($result !== true) {
        //                throw new Exception('平台SKU刊登图片，第 '. $line. ' 行，第'. ($k + 1). ' 张图'. $result);
        //            }
        //        }
        //    } else {
        //        $result = $this->checkField($field['swatch'], $rule);
        //        if($result !== true) {
        //            throw new Exception('平台SKU刊登图片，第 '. $line. ' 行图片'. $result);
        //        }
        //    }
        //}

        //比较变体值，不能一样了,否则上变体的时候，会连不上父子关系；
        $variantSkuValLineArr = [];
        $variantSkuValArr = [];
        $key = 'sku';
        $publish_skus = [];
        foreach ($data['sku'] as $linezero => $arr) {
            $line = $linezero + 1;
            //和SKU不一样的，就需要检查；
            if ($arr['PublishSKU'] != $arr['SKU']) {
                if (strlen($arr['PublishSKU']) - strlen($arr['SKU']) >= 4) {
                    $publish_skus[$line] = $arr['PublishSKU'];
                }
                //if (strlen($arr['PublishSKU']) - strlen($arr['SKU']) === false) {
                //    if ($lang == 'zh') {
                //        throw new Exception('SKU设置，第' . $line . '行，自定义SKU：' . $arr['PublishSKU'] . '必须包含原sku：' . $arr['SKU'] . '，前几位不一致');
                //    } else {
                //        throw new Exception('SKU settings, No.' . $line . ' Line, custom SKU: ' . $arr['PublishSKU'] . ' Must contain the original sku: ' . $arr['SKU'] . ', the first few inconsistencies');
                //    }
                //} else {
                //}
            }
            //验证固定属性；
            foreach ($arr as $k => $field) {
                //属于变体的值则跳过；
                if (isset($variantRule[$k]) || strpos($k, '@') !== false) {
                    continue;
                }
                //除去变体的值，如有找不到规则的，则于错参数错误
                if (!isset($ruleArr[$key][$k])) {
                    if ($lang == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，存在错误参数 ' . $k);
                    } else {
                        throw new Exception('SKU setting, No.' . $line . ' Line, error parameter ' . $k);
                    }
                }
                if (is_string($field) && json_decode($field)) {
                    $field = json_decode($field, true);
                }
                $rule = $ruleArr[$key][$k];
                if (is_array($field)) {
                    foreach ($field as $f) {
                        $result = $this->checkField($f, $rule);
                        if ($result !== true) {
                            if ($lang == 'zh') {
                                throw new Exception('SKU设置，第' . $line . '行，' . $k . ' 参数值' . $result);
                            } else {
                                throw new Exception('SKU settings, No.' . $line . ' Line,' . $k . ' Parameter value ' . $result);
                            }
                        }
                    }
                } else {
                    $result = $this->checkField($field, $rule);
                    if ($result !== true) {
                        if ($lang == 'zh') {
                            throw new Exception('SKU设置，第' . $line . '行，' . $k . ' 参数值' . $result);
                        } else {
                            throw new Exception('SKU settings, No.' . $line . ' Line,' . $k . ' Parameter value ' . $result);
                        }
                    }
                }

                if (in_array($k, ['StandardPrice'])) {
                    if (!is_numeric($field) || $field <= 0) {
                        if ($lang == 'zh') {
                            throw new Exception('SKU设置，第' . $line . '行，' . $k . ' 价格参数值' . $field . '必须大于0');
                        } else {
                            throw new Exception('SKU settings, No.' . $line . '行，' . $k . ' Price parameter value ' . $field . ' Must be greater than 0');
                        }
                    }
                }
            }

            //验证活动3个字段
            if (!empty($arr['StartDate']) && !empty($arr['EndDate']) & !empty($arr['SalePrice'])) {
                $arr['SalePrice'] = trim($arr['SalePrice']);
                if (!is_numeric($arr['SalePrice']) || $arr['SalePrice'] <= 0) {
                    if ($lang == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，SalePrice 价格参数值' . $arr['SalePrice'] . '必须大于0');
                    } else {
                        throw new Exception('SKU setting, No.' . $line . ' Line, SalePrice price parameter value ' . $arr['SalePrice'] . ' Must be greater than 0');
                    }
                }
                $StartDate = strtotime($arr['StartDate']);
                $EndDate = strtotime($arr['EndDate']);
                if (empty($StartDate) || empty($EndDate)) {
                    if ($lang == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，Sale Start Date、Sale End Date 参数值 不是正确的日期');
                    } else {
                        throw new Exception('SKU setting, No.' . $line . ' Line, Sale Start Date, Sale End Date parameter value is not the correct date');
                    }
                }

                $timestart = strtotime(date('Y-m-d', time()));
                if (!empty($data['basic']['Timer'])) {
                    $timer = strtotime(date('Y-m-d', strtotime($data['basic']['Timer'])));
                    $timestart = $timer < $timestart ? $timestart : $timer;
                }

                if ($EndDate < $timestart || $EndDate < $StartDate) {
                    if ($lang == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，Sale End Date之间Sale Start Date必须包含当天时间');
                    } else {
                        throw new Exception('SKU settings, No.' . $line . ' Line, Between Sale End Date and Sale Start Date must be included in the day of the day');
                    }
                }
            }

            //验证变体属性；
            foreach ($variantRule as $variantKey => $rule) {
                if (!isset($arr[$variantKey])) {
                    if ($lang == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，缺少变体' . $variantKey . '的值');
                    } else {
                        throw new Exception('SKU settings, No.' . $line . ' Line, missing variant ' . $variantKey . ' value');
                    }
                }
                //找出值；
                $field = $arr[$variantKey];
                $result = $this->checkField($field, $rule);
                if ($result !== true) {
                    if ($lang == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，变体' . $variantKey . ' 参数值' . $result);
                    } else {
                        throw new Exception('SKU settings, No.' . $line . ' Line, variant ' . $variantKey . ' Parameter value ' . $result);
                    }
                }
                $variantSkuValLineArr[$variantKey][trim($field)][] = $line;
                $variantSkuValArr[$line][$variantKey] = trim($field);
            }
        }

        if (!empty($variantSkuValLineArr)) {
            $this->checkVariantValue($theme, $variantSkuValLineArr, $variantSkuValArr, $lang);
        }

        if (!empty($publish_skus)) {
            $this->checkPublishSkus($publish_skus, ($data['id'] ?? 0), ($data['account_id'] ?? 0));
        }

        //图片的验试省去，以上没抛出异常，则验证通过；
        return true;
    }


    public function checkPublishSkus($publish_skus, $id, $account_id)
    {
        $datas = AmazonPublishProductDetail::alias('d')
            ->join(['amazon_publish_product' => 'p'], 'p.id=d.product_id')
            ->where(['d.publish_sku' => ['in', array_values($publish_skus)], 'p.account_id' => $account_id])
            ->field('product_id,publish_sku')
            ->select();
        foreach ($publish_skus as $line => $publish_sku) {
            foreach ($datas as $val) {
                if ($val['publish_sku'] == $publish_sku && $val['product_id'] != $id) {
                    if ($this->getLang() == 'zh') {
                        throw new Exception('SKU设置，第' . $line . '行，自定义SKU：' . $publish_sku . ' 与历史数据重复');
                    } else {
                        throw new Exception('SKU settings, No.' . $line . ' Line, custom SKU: ' . $publish_sku . ' Repeat with historical data');
                    }
                }
            }
        }
    }


    /**
     * @param $vkey 变体名称
     * @param $variantArr 变体装的数绷；
     */
    public function checkVariantValue($vkey, $variantLineArr, $variantArr, $lang = 'zh')
    {
        $err = '';
        //先处理变体名称；
        $vkey = str_replace('-', '', $vkey);
        $vkey = trim(preg_replace('@([A-Z]{1})@', '_$1', $vkey), ' _');
        $vkeyArr = explode('_', $vkey);
        $check = false;

        //两种检测方式，一种是单变体，一种是多变体；
        if (count($vkeyArr) == 1) {
            if (!empty($variantLineArr[$vkeyArr[0]])) {
                $check = true;
                $variantKey = $vkeyArr[0];
                //只有一个变体时，会被传成单体，也不会重复，所以这个可以忽略
                foreach ($variantLineArr[$variantKey] as $variantVal => $lineArr) {
                    if (count($lineArr) >= 2) {
                        if ($lang == 'zh') {
                            $err = 'SKU设置，第' . implode(',', $lineArr) . '行，变体 ' . $variantKey . ' 参数值 "' . $variantVal . '" 出现重复';
                        } else {
                            $err = 'SKU setting, No.' . implode(',', $lineArr) . ' Line, variant ' . $variantKey . ' Parameter value "' . $variantVal . '" Duplicate occurrence';
                        }
                        throw new Exception($err);
                    }
                }

                if (!empty($variantLineArr[$vkeyArr[0] . 'Map'])) {
                    $variantKey = $vkeyArr[0] . 'Map';
                    //只有一个变体时，会被传成单体，也不会重复，所以这个可以忽略
                    foreach ($variantLineArr[$variantKey] as $variantVal => $lineArr) {
                        if (count($lineArr) >= 2) {
                            if ($lang == 'zh') {
                                $err = 'SKU设置，第' . implode(',', $lineArr) . '行，变体 ' . $variantKey . ' 参数值 "' . $variantVal . '" 出现重复';
                            } else {
                                $err = 'SKU setting, No.' . implode(',', $lineArr) . ' Line, variant ' . $variantKey . ' Parameter value "' . $variantVal . '" Duplicate occurrence';
                            }
                            throw new Exception($err);
                        }
                    }
                }
            }
        } else {

            $allIn = true;
            foreach ($vkeyArr as $variantKey) {
                if (!isset($variantLineArr[$variantKey])) {
                    $allIn = false;
                }
            }

            if ($allIn) {
                $check = true;
                $newArr = [];
                $newmapArr = [];
                foreach ($variantArr as $line => $valArr) {
                    $str = '';
                    $strmap = '';
                    foreach ($vkeyArr as $variantKey) {
                        $str .= $variantKey . '-' . $valArr[$variantKey] . '-';
                        $strmap .= $variantKey . '-' . $valArr[$variantKey] . '-';
                        $mapkey = $variantKey . 'Map';
                        if (isset($valArr[$mapkey])) {
                            $strmap .= $mapkey . '-' . $valArr[$mapkey] . '-';
                        }
                    }
                    $newArr[$str][] = $line;
                    $newmapArr[$strmap][] = $line;
                }
                //带MAP检查；
                foreach ($newmapArr as $lineArr) {
                    if (count($lineArr) >= 2) {
                        if ($lang == 'zh') {
                            $err = 'SKU设置，第' . implode(',', $lineArr) . '行，变体 ' . implode(',', $vkeyArr) . '及Map 参数值出现重复';
                        } else {
                            $err = 'SKU setting, No.' . implode(',', $lineArr) . ' Line, variant ' . implode(',', $vkeyArr) . ' and variant Map Duplicate occurrence';
                        }
                        throw new Exception($err);
                    }
                }

                //不带map检查
                foreach ($newArr as $lineArr) {
                    if (count($lineArr) >= 2) {
                        if ($lang == 'zh') {
                            $err = 'SKU设置，第' . implode(',', $lineArr) . '行，变体 ' . implode(',', $vkeyArr) . ' 参数值出现重复';
                        } else {
                            $err = 'SKU setting, No.' . implode(',', $lineArr) . ' Line, variant ' . implode(',', $vkeyArr) . ' Duplicate occurrence';
                        }
                        throw new Exception($err);
                    }
                }
            }
        }

        //以上如果没有检测，则在这里检测；
        if (!$check) {
            $newArr = [];
            foreach ($variantArr as $line => $valArr) {
                $str = '';
                foreach ($valArr as $variantKey => $variantVal) {
                    $str .= $variantKey . '-' . $variantVal . '-';
                }
                $newArr[$str][] = $line;
            }

            foreach ($newArr as $lineArr) {
                if (count($lineArr) >= 2) {
                    if ($lang == 'zh') {
                        $err = 'SKU设置，第' . implode(',', $lineArr) . '行，变体参数值出现重复';
                    } else {
                        $err = 'SKU setting, No.' . implode(',', $lineArr) . ' Line, variant Duplicate occurrence';
                    }
                    throw new Exception($err);
                }
            }
        }
        return $err;
    }

    /**
     * 根据规则验证字段
     * @param string $field
     * @param $rule
     * @return bool|string
     */
    public function checkField(string $field, $rule = [])
    {
        if (empty($rule)) {
            return true;
        }
        $lang = $this->getLang();

        //必填统一
        $required = 0;
        if ((isset($rule['require']) && $rule['require'] == 1) || (isset($rule['required']) && $rule['required'] == 1)) {
            $required = 1;
        }

        //必填项不能为空；
        if ($required === 1 && $field === '') {
            if ($lang == 'zh') {
                return $field . '为必填项，不能为空';
            } else {
                return $field . ' Required, cannot be empty ';
            }
        }

        //上面必填项为空验证通过，这里为空，则不是必填项，则不需要以下验证；
        if ($field === '') {
            return true;
        }

        $ext = mb_strlen($field) > 120 ? '...' : '';
        $returnField = mb_substr($field, 0, 120). $ext;

        //1.验证是否select;
        if (isset($rule['select']) && $rule['select'] && is_array($rule['option'])) {
            if (!in_array($field, $rule['option'])) {
                if ($lang == 'zh') {
                    return $returnField . '不在选项里';
                } else {
                    return $returnField . ' Not in the option ';
                }
            }
        }
        if (isset($rule['totalDigits']) && $rule['totalDigits'] !== '') {
            if (!is_numeric($field)) {
                if ($lang == 'zh') {
                    return $returnField . '不是一个数字';
                } else {
                    return $returnField . ' Not a number ';
                }
            }
            $maxNum = pow(10, $rule['totalDigits']);
            if ($field >= $maxNum) {
                if ($lang == 'zh') {
                    return $returnField . '位数超过' . $rule['totalDigits'] . '位';
                } else {
                    return $returnField . ' The number of digits exceeds ' . $rule['totalDigits'] . ' bit';
                }
            }
        }
        if (isset($rule['maxLength']) && $rule['maxLength'] !== '' && mb_strlen($field) > $rule['maxLength']) {
            if ($lang == 'zh') {
                return $returnField . '长度超过' . $rule['maxLength'] . '位';
            } else {
                return $returnField . ' Length exceeds ' . $rule['maxLength'] . ' bit';
            }
        }

        if (isset($rule['minLength']) && $rule['minLength'] !== '' && mb_strlen($field) < $rule['minLength']) {
            if ($lang == 'zh') {
                return $returnField . '长度小于' . $rule['minLength'] . '位';
            } else {
                return $returnField . ' The length is less than ' . $rule['minLength'] . ' bit';
            }
        }

        //检查四字节字符；
        $arr = preg_split('//u', $field);
        foreach ($arr as $val) {
            if (strlen($val) >= 4) {
                if ($lang == 'zh') {
                    return $returnField . '出现无法保存四字节字符' . $val . '请删除';
                } else {
                    return $returnField . ' Unable to save four-byte characters  ' . $val . ' Please delet ';
                }
            }
        }

        if (isset($rule['pattern']) && $rule['pattern'] !== '') {
            try {
                $pattern = $rule['pattern'];
                //有的数据是亚马逊后台允许有空格，XSD正则不允许有空格，此处先把空格去掉！测试；
                $pattern = ($pattern == "[^\\r\\n\\t\\s]") ? "[^\\r\\n\\t]" : $pattern;
                $length = strlen($pattern);
                //给[\r\n\t\s] 类似的正则加上数量
                if (strpos($pattern, '[') === 0 && strrpos($pattern, ']') === ($length - 1)) {
                    $pattern = $pattern . '*';
                }
                if (strpos($pattern, '/') !== 0 && strpos($pattern, '@') !== 0 && strpos($pattern, '^') !== 0) {
                    $pattern = '@^' . $pattern . '$@';
                }
                if (strpos($pattern, '/') !== 0 && strpos($pattern, '@') !== 0) {
                    $pattern = '@' . $pattern . '@';
                }
                if (!preg_match($pattern, $field)) {
                    //return $returnField. '匹配正则'. $rule['pattern']. '错误';
                    if ($lang == 'zh') {
                        return $returnField . '格式错误';
                    } else {
                        return $returnField . ' wrong format ';
                    }
                }
            } catch (Exception $e) {
                $emsg = 'String:' . $returnField . ';Pattern:' . $pattern . ';Msg:' . $e->getMessage();
                return $emsg;
            }
        }

        return true;
    }

    public function checkDescription($dec, $lang = 'zh')
    {
        try {
            $pattern = '@<\w{1,20}[^>]+style[^>]*>@i';
            if (preg_match($pattern, $dec)) {
                if ($lang == 'zh') {
                    return ' 含有CSS无效，如(style="...")，请点击5按钮查看、或清除格式';
                } else {
                    return ' Invalid CSS, such as (style="..."), please click the 5 button to view, or clear the format';
                }
            }
            $pattern = '@<\w{1,20}[^>]+href[^>]*>@i';
            if (preg_match($pattern, $dec)) {
                if ($lang == 'zh') {
                    return ' 含有a标签超链接，如( <a href="...">...</a> )，请点击5按钮查看、或清除格式';
                } else {
                    return ' Contain a tag hyperlink, such as ( <a href="...">...</a> ), click the 5 button to view, or clear the format';
                }
            }
            //匹配出所有的HTML标签；
            $allowedArr = ['b', 'p', 'span', 'br'];
            $pattern = '@<(\w{1,30})>@si';
            preg_match_all($pattern, $dec, $data);
            if (empty($data[1])) {
                return true;
            }

            $result = '';
            foreach ($data[1] as $v) {
                if (!in_array($v, $allowedArr)) {
                    $result .= '<' . $v . '>';
                }
            }

            if (!empty($result)) {
                if ($lang == 'zh') {
                    return '含有无效标签' . $result . '，请点击5按钮查看、或清除格式';
                } else {
                    return ' Contain a invalid tag ' . mb_substr($result, 0, 120) . '，please click the 5 button to view, or clear the format';
                }
            }
        } catch (Exception $e) {
            $emsg = 'String:' . mb_substr($dec, 0, 120) . '...;Pattern:' . $pattern . ';Msg:' . $e->getMessage();
            throw new Exception($emsg);
        }
        return true;
    }

    /**
     * 检测刊登数据；
     * @param $list
     * @return bool
     * @throws Exception
     */
    public function checkPublishList(&$list)
    {
        $noteNo = count($list) == 1 ? false : true;
        $lang = $this->getLang();
        $before = '';
        foreach ($list as $key => $data) {
            //验证保存的登刊登参数；
            try {
                if ($lang == 'zh') {
                    $before = $noteNo ? '第' . ($key + 1) . '个帐号,' : '';
                    //验测第一个元素是不是父产品；
                    if ($data['basic']['Spu'] != $data['descript'][0]['SKU']) {
                        throw new Exception('标题描述，第一个对象应该是父产品信息');
                    }

                    if (count($data['descript']) > 1 + count($data['sku'])) {
                        throw new Exception('标题描述sku数量 超过 SKU设置部分 sku的数量');
                    }

                    if (count($data['descript']) < 1 + count($data['sku'])) {
                        throw new Exception('标题描述sku数量 少于 SKU设置 sku的数量');
                    }
                    //验证顺序
                    foreach ($data['descript'] as $key2 => $val) {
                        $this->replaceTitle($list[$key]['descript'][$key2]['Title']);
                        if ($key2 == 0) {
                            continue;
                        }
                        if ($val['SKU'] != $data['sku'][$key2 - 1]['SKU']) {
                            throw new Exception('标题描述和SKU部分段落顺序对应不上');
                        }
                    }

                    $tmpPublishSkuArr = [];
                    //验证
                    foreach ($data['sku'] as $skuKey => $skuData) {
                        $line = $skuKey + 1;
                        if (empty($skuData['PublishSKU'])) {
                            throw new Exception('SKU部分，第' . $line . '行，自定义SKU为空');
                        }
                        if ($skuData['PublishSKU'] !== $skuData['SKU'] && strlen($skuData['PublishSKU']) < strlen($skuData['SKU'])) {
                            throw new Exception('SKU部分，第' . $line . '行，自定义SKU长度小于系统SKU');
                        }
                        if (in_array($skuData['PublishSKU'], $tmpPublishSkuArr)) {
                            throw new Exception('SKU部分，第' . $line . '行，自定义SKU:' . $skuData['PublishSKU'] . '出现重复');
                        }
                        $tmpPublishSkuArr[] = $skuData['PublishSKU'];
                    }
                } else {
                    $before = $noteNo ? 'No.' . ($key + 1) . ' accounts, ' : '';
                    if ($data['basic']['Spu'] != $data['descript'][0]['SKU']) {
                        throw new Exception('Title description, the first object should be the main product information');
                    }

                    if (count($data['descript']) > 1 + count($data['sku'])) {
                        throw new Exception('The title description sku number exceeds the SKU setting part sku number');
                    }

                    if (count($data['descript']) < 1 + count($data['sku'])) {
                        throw new Exception('The title description sku number is less than the SKU setting sku number');
                    }
                    //验证顺序
                    foreach ($data['descript'] as $key2 => $val) {
                        $this->replaceTitle($list[$key]['descript'][$key2]['Title']);
                        if ($key2 == 0) {
                            continue;
                        }
                        if ($val['SKU'] != $data['sku'][$key2 - 1]['SKU']) {
                            throw new Exception('The title description does not correspond to the SKU part paragraph order');
                        }
                    }

                    $tmpPublishSkuArr = [];
                    //验证
                    foreach ($data['sku'] as $skuKey => $skuData) {
                        $line = $skuKey + 1;
                        if (empty($skuData['PublishSKU'])) {
                            throw new Exception('SKU part, No.' . $line . 'line, custom SKU is empty');
                        }
                        if ($skuData['PublishSKU'] !== $skuData['SKU'] && strlen($skuData['PublishSKU']) < strlen($skuData['SKU'])) {
                            throw new Exception('SKU part, No.' . $line . 'line, custom SKU length is less than system SKU');
                        }
                        if (in_array($skuData['PublishSKU'], $tmpPublishSkuArr)) {
                            throw new Exception('SKU part, No.' . $line . 'line, 自定义SKU:' . $skuData['PublishSKU'] . 'Repeat');
                        }
                        $tmpPublishSkuArr[] = $skuData['PublishSKU'];
                    }
                }
                $this->checkPublishData($data, $lang);
            } catch (Exception $e) {
                throw new Exception($before . $e->getMessage());
            }
        }
        return true;
    }


    private $saveReplace = false;


    public function getSaveReplace()
    {
        return $this->saveReplace;
    }


    public function replaceTitle(&$title)
    {
        $length = mb_strlen($title, 'utf-8');
        $check = ['⑧', '!', '*', '￡', '?', '%', 'Lightning', 'Terrific Item', 'Best Seller', 'Sale', 'Free Delivery', 'Great Gift', 'Hot Sale', 'Christmas Sale', 'Available in different colors', 'Brand new', 'Best Seller', 'Seen on TV', 'Popular Best', 'Top Seller', 'Offer of the day', 'Custom size', 'Best Gift Ever', '100% Quantity', 'free worldwide shipping', 'sexy', 'FREE DELIVERY', 'Wholesale', '2-3 days shipping', 'Buy 2 Get 1 Free', 'Guaranteed or Monday Back', 'Bestseller', 'NEW COLORS AVAIABLE', 'new arrival', '1 Best Rated', 'Money Back', 'Limited edition', 'Not to miss', 'Perfect Fit', 'Great price', 'prime day deals', 'High Quality', 'ALL Other Sizes and Styles on Request', 'The package will arrive before Christmas', '[100%]Satisfaction Guaranteed'];
        $title = str_replace($check, '', $title);
        if (mb_strlen($title, 'utf-8') != $length) {
            $this->saveReplace = true;
        }
    }


    /**
     * 设置刊登语言
     * @param $lang
     */
    public function setLang($lang)
    {
        $this->lang = $lang;
    }


    /**
     * 获取刊登语言
     * @return string
     */
    public function getLang()
    {
        return $this->lang ?? 'zh';
    }


    /**
     *
     * 保存刊登的数据；
     * @param $data
     */
    public function savePublishData($list, $uid)
    {
        //data列检如果是string就解码下；
        $list = is_string($list) ? json_decode($list, true) : $list;
        $lang = $this->getLang();

        //先进行检测；
        $this->checkPublishList($list);
        $publishProductModel = new AmazonPublishProduct();
        $docModel = new AmazonPublishDocModel();
        $cache = Cache::store('AmazonAccount');

        //找出旧数据；
        $oldProductList = [];
        foreach ($list as $data) {
            $account = $cache->getAccount($data['account_id']);
            if (empty($account)) {
                if ($lang == 'zh') {
                    throw new Exception('未知Amazon帐号ID：' . $data['account_id']);
                } else {
                    throw new Exception('Unknown Amazon account ID:' . $data['account_id']);
                }
            }
            //先查看是编辑还是新增，复制属于新增；
            if (empty($data['id'])) {
                continue;
            }
            $oldProduct = $publishProductModel->where(['id' => $data['id']])->field('id,publish_status,product_status,relation_status,quantity_status,image_status,price_status,creator_id')->find();
            if (empty($oldProduct)) {
                if ($lang == 'zh') {
                    throw new Exception('Amazon帐号：' . $account['code'] . '编辑数据传入未知ID');
                } else {
                    throw new Exception('Amazon account:' . $account['code'] . 'Edit data passed in unknown ID');
                }
            }
            //if ($oldProduct['publish_status'] != 4) {
            //  throw new Exception('Amazon帐号：'. $account['code']. '编辑数据，状态不正确，请检查是否多人同时在编辑数据');
            //}
            $oldProductList[$data['id']] = $oldProduct;
        }

        //$productCache = Cache::store('AmazonPublish');
        //用来装放入队列的数组
        $queueIds = [];
        try {
            $time = time();

            foreach ($list as $data) {
                //范本ID；
                $doc_id = $data['doc_id'] ?? 0;
                $product = [];
                try {
                    //先查看是编辑还是新增，复制属于新增；
                    $product['id'] = 0;
                    $oldProduct = [];
                    if (!empty($data['id'])) {
                        $product['id'] = $data['id'];
                        $oldProduct = $oldProductList[$data['id']];
                    }
                    //先保存amazon_publish_product数据
                    $product['site'] = $data['site'];
                    $product['account_id'] = $data['account_id'];
                    $product['category_id'] = $data['category_id'] ?? 0;
                    //是否已翻译；
                    $product['is_translate'] = $data['is_translate'] ?? 0;
                    $product['goods_id'] = $data['goods_id'];
                    if (empty($data['id'])) {
                        $product['doc_id'] = $doc_id;
                    }
                    $product['warehouse_id'] = $data['basic']['warehouse_id'];

                    $product['spu'] = $data['basic']['Spu'];
                    $product['item_type'] = $data['basic']['ItemType'];
                    //可能会有两个元素,逗号分隔；
                    $nodeArr = [];
                    if (!empty($data['basic']['RecommendedBrowseNode'])) {
                        $nodeArr = explode(',', $data['basic']['RecommendedBrowseNode']);
                        $nodeArr = array_map(function ($str) {
                            return trim($str);
                        }, $nodeArr);
                        //如果有两个以上，也最多只保留两个；
                        $product['recommend_node'] = implode(',', array_slice($nodeArr, 0, 2));
                    } else {
                        $product['recommend_node'] = '';
                    }
                    $product['department'] = $data['basic']['Department'];
                    $product['brand'] = $data['basic']['Brand'];
                    $product['theme_name'] = $data['basic']['VariationTheme'] ?? 0;

                    $product['save_map'] = $data['basic']['SaveMap'] ?? 1;

                    //给时间转换一下；
                    $product['timer'] = 0;
                    if (!empty($data['basic']['Timer'])) {
                        //当定时刊登时间小于当前时间10分钟内的，将被清掉；
                        $timer = strtotime($data['basic']['Timer']);
                        $product['timer'] = ($timer < $time + 600) ? 0 : $timer;
                    }
                    $product['is_virtual_send'] = $data['basic']['IsVirtualSend'] ?? 0;

                    $product['category_template_id'] = $data['category_template']['id'];
                    $product['category_info'] = json_encode($data['category_template'], JSON_UNESCAPED_UNICODE);

                    $product['product_template_id'] = $data['product_template']['id'];
                    //删除产品模板不存在的数据；
                    foreach ($this->productUnset as $unval) {
                        if (isset($data['product_template'][$unval])) {
                            unset($data['product_template'][$unval]);
                        }
                    }

                    //修改产品模板的SpuPartNumber;
                    if (isset($data['product_template']['SpuPartNumber'])) {
                        $data['product_template']['SpuPartNumber'] = trim($data['product_template']['SpuPartNumber']);
                        if ($data['product_template']['SpuPartNumber'] == '' || $data['product_template']['SpuPartNumber'] == $product['spu']) {
                            $data['product_template']['SpuPartNumber'] = $this->getRandomPartNumber($product['spu']);
                        }
                    }
                    $product['product_info'] = json_encode($data['product_template'], JSON_UNESCAPED_UNICODE);

                    //只要编辑了，就把刊登状态变更为待刊登；
                    $product['publish_status'] = 0;
                    $product['update_time'] = $time;

                    if (empty($product['id'])) {
                        $product['creator_id'] = $uid;
                        $product['create_time'] = $time;
                    }

                    if (isset($oldProduct['product_status']) && $oldProduct['product_status'] != 2) {
                        $product['product_status'] = 0;
                    }
                    if (isset($oldProduct['relation_status']) && $oldProduct['relation_status'] != 2) {
                        $product['relation_status'] = 0;
                    }
                    if (isset($oldProduct['quantity_status']) && $oldProduct['quantity_status'] != 2) {
                        $product['quantity_status'] = 0;
                    }
                    if (isset($oldProduct['image_status']) && $oldProduct['image_status'] != 2) {
                        $product['image_status'] = 0;
                    }
                    if (isset($oldProduct['price_status']) && $oldProduct['price_status'] != 2) {
                        $product['price_status'] = 0;
                    }

                    //保存amazon_publish_product_detail表数据；
                    $detailList = [];
                    foreach ($data['descript'] as $key => $descript) {
                        $detail = [];

                        $detail['product_id'] = $product['id'];
                        //第一个数组数据是父体；
                        $detail['type'] = $key == 0 ? 0 : 1;

                        //先保存descript里面的数据------------------；
                        $detail['title'] = $descript['Title'];
                        $detail['search_Terms'] = is_string($descript['SearchTerms']) ? $descript['SearchTerms'] : json_encode($descript['SearchTerms'], JSON_UNESCAPED_UNICODE);
                        $detail['bullet_point'] = is_string($descript['BulletPoint']) ? $descript['BulletPoint'] : json_encode($descript['BulletPoint'], JSON_UNESCAPED_UNICODE);
                        $detail['description'] = $descript['Description'];

                        //保存sku里面的数据------------------------；
                        //以下数据key错对一位；
                        //sku第一个数组可能是空数组,所以需要判断保存；
                        $detail['sku'] = $detail['type'] ? $data['sku'][$key - 1]['SKU'] : $data['basic']['Spu'];

                        $detail['recommend_node'] = $product['recommend_node'];
                        //第一部分是没有SKU的；
                        if ($key == 0) {
                            $detail['sku_id'] = 0;
                            $detail['publish_sku'] = $product['spu'];
                            $detail['part_number'] = '';
                            //绑定销售
                            $detail['binding_goods'] = '';
                        } else {
                            $detail['sku_id'] = $data['sku'][$key - 1]['SKUID'] ?? 0;
                            $detail['publish_sku'] = $data['sku'][$key - 1]['PublishSKU'];
                            $detail['part_number'] = $data['sku'][$key - 1]['PartNumber'] ?? '';
                            //如果详情里面有传，则要附值；
                            if (!empty($data['sku'][$key - 1]['RecommendedNode'])) {
                                $detail['recommend_node'] = $data['sku'][$key - 1]['RecommendedNode'];
                            }
                            //绑定销售
                            $detail['binding_goods'] = $data['sku'][$key - 1]['BindingGoods'] ?? '';
                        }

                        $detail['product_id_type'] = $data['sku'][$key - 1]['ProductIDType'] ?? '';
                        $detail['product_id_value'] = $data['sku'][$key - 1]['ProductIdValue'] ?? '';
                        $detail['condition_type'] = $data['sku'][$key - 1]['ConditionType'] ?? '';
                        $detail['condition_note'] = $data['sku'][$key - 1]['ConditionNote'] ?? '';

                        //标准价格
                        $detail['standard_price'] = $data['sku'][$key - 1]['StandardPrice'] ?? 0;

                        //活动起始日期和活动价钱
                        if (
                            !empty($data['sku'][$key - 1]['SalePrice']) &&
                            !empty($data['sku'][$key - 1]['StartDate']) &&
                            !empty($data['sku'][$key - 1]['EndDate']) &&
                            strtotime($data['sku'][$key - 1]['StartDate']) != false &&
                            strtotime($data['sku'][$key - 1]['EndDate']) != false
                        ) {
                            $detail['sale_price'] = $data['sku'][$key - 1]['SalePrice'];
                            $detail['sale_start_date'] = strtotime($data['sku'][$key - 1]['StartDate']);
                            $detail['sale_end_date'] = strtotime($data['sku'][$key - 1]['EndDate']);
                        }

                        //if (is_array($detail['binding_goods'])) {
                        //    $detail['binding_goods'] = json_encode($detail['binding_goods'], JSON_UNESCAPED_UNICODE);
                        //}

                        $detail['quantity'] = $data['sku'][$key - 1]['Quantity'] ?? 0;
                        $detail['error_message'] = '[]';
                        $detail['warning_message'] = '[]';

                        //装变体数据；
                        $variant_info = [];
                        foreach ($this->variantKey[$data['account_id']] as $vkey) {
                            if ($key > 0) {
                                if (!isset($data['sku'][$key - 1][$vkey])) {
                                    if ($lang == 'zh') {
                                        throw new Exception('SKU部分，第' . $key . '条参缺少变体参数' . $vkey);
                                    } else {
                                        throw new Exception('SKU part, no.' . $key . 'parameter lacks the variant parameter:' . $vkey);
                                    }
                                }
                                $variant_info[$vkey] = $data['sku'][$key - 1][$vkey];
                            }
                        }
                        $detail['variant_info'] = json_encode($variant_info, JSON_UNESCAPED_UNICODE);

                        //解析图片
                        $detail['main_image'] = '';
                        $detail['swatch_image'] = '';
                        $detail['other_image'] = [];
                        $other_number = 0;
                        //保存图片；
                        if ($key == 0) {
                            if (!empty($data['img']['SpuImage'])) {
                                foreach ($data['img']['SpuImage'] as $keyi => $val) {
                                    if ($keyi == 0) {
                                        $detail['main_image'] = $val['path'];
                                        break;
                                    }
                                    //$detail['other_image'][] = $val['path'];
                                }
                            }
                        } else {
                            //sku对应的图片列表；
                            $imgList = [];
                            //如果SkuImage 是以数组形势存在的，则是新的数据，否则是旧的数据；
                            if (isset($data['img']['SkuImage'][0])) {
                                if (isset($data['img']['SkuImage'][$key - 1]['data']) && is_array($data['img']['SkuImage'][$key - 1]['data'])) {
                                    $imgList = $data['img']['SkuImage'][$key - 1]['data'];
                                }
                            } else {
                                $imgList = $data['img']['SkuImage'][$descript['SKU']] ?? [];
                            }
                            foreach ($imgList as $ikey => $val) {
                                if (isset($val['is_default']) && $val['is_default'] == 1 && empty($detail['main_image'])) {
                                    $detail['main_image'] = $val['path'];
                                    unset($imgList[$ikey]);
                                }
                                if (isset($val['is_swatch']) && $val['is_swatch'] == true && empty($detail['swatch_image'])) {
                                    $detail['swatch_image'] = $val['path'];
                                    unset($imgList[$ikey]);
                                }
                            }
                            //以上没有标记主图的，把第一张给主图，如果有主图，则其余的应该全部都是其它图片
                            foreach ($imgList as $ikey => $val) {
                                if (empty($ikey) && empty($detail['main_image'])) {
                                    $detail['main_image'] = $val['path'];
                                } else {
                                    if ($other_number < 7) {
                                        $detail['other_image'][] = $val['path'];
                                        $other_number++;
                                    } else {
                                        break;
                                    }
                                }
                            }
                        }
                        //把other_image转成数组；
                        $detail['other_image'] = json_encode($detail['other_image'], JSON_UNESCAPED_UNICODE);
                        $detailList[] = $detail;
                    }

                    //如果是编辑的数据，那么把之前提交的submissionId标为失效；
                    if (!empty($product['id'])) {
                        //$this->defeatSubmissionId($product['id']);
                    }

                    $product_id = $this->saveProductDetailData(['product' => $product, 'detailList' => $detailList]);

                    //在新增时，如有有doc_id，则更新范本引用数量；
                    if (!empty($product['id']) && $doc_id) {
                        $doc = $docModel->field('id,use_total')->get($doc_id);
                        if (!empty($doc)) {
                            $doc->save(['use_total' => ($doc['use_total'] + 1)]);
                        }
                    }
                } catch (\Exception $e) {
                    throw new Exception($e->getMessage() . $e->getLine());
                }

                $type = [];
                //保存数据进缓存；
                $queueIds[] = ['id' => $product_id, 'total' => count($detailList), 'timer' => $product['timer']];
                //编辑删掉缓存；
                // Cache::handler()->hSet('task:Amazon:detail', $product_id, json_encode($data, JSON_UNESCAPED_UNICODE));
            }

            $typeQueueArr = [
                1 => AmazonPublishProductQueuer::class,
                2 => AmazonPublishRelationQueuer::class,
                3 => AmazonPublishQuantityQueuer::class,
                4 => AmazonPublishImageQueuer::class,
                5 => AmazonPublishPriceQueuer::class
            ];


            //正式站才进行刊登，先禁用下面代码，不再保存后就进入队列；
            if (1 === 2 && (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] != '172.18.8.242')) {
                $pushTypeArr = [];
                foreach ($queueIds as $param) {
                    if ($param['total'] < 2) {
                        continue;
                    }
                    if ($param['timer'] < $time + 20 * 60) {
                        $product = $publishProductModel->where(['id' => $param['id']])->field('id,account_id,product_status,relation_status,quantity_status,image_status,price_status,creator_id,create_time')->find();
                        if (empty($product)) {
                            continue;
                        }
                        if ($product['product_status'] == 0) {
                            $pushTypeArr[1][$product['creator_id']] = $product['create_time'];
                        } else if ($product['product_status'] == 2) {
                            if ($product['relation_status'] == 0 && $param['total'] > 2) {
                                $pushTypeArr[2][$product['creator_id']] = $product['create_time'];
                            }
                            if ($product['quantity_status'] == 0) {
                                $pushTypeArr[3][$product['creator_id']] = $product['create_time'];
                            }
                            if ($product['image_status'] == 0) {
                                $pushTypeArr[4][$product['creator_id']] = $product['create_time'];
                            }
                            if ($product['price_status'] == 0) {
                                $pushTypeArr[5][$product['creator_id']] = $product['create_time'];
                            }
                        }
                    }
                }
                /** @var $cache \app\common\cache\driver\AmazonPublish */
                $cache = Cache::store('AmazonPublish');
                foreach ($pushTypeArr as $type => $accountIdArr) {
                    //$accountIdArr = array_unique(array_filter($accountIdArr));
                    $level = $cache->getPublishLevel($type);
                    $queue = new UniqueQueuer($typeQueueArr[$type]);
                    foreach ($accountIdArr as $creator_id=>$create_time) {
                        if ($create_time - $level * 10 < 0) {
                            continue;
                        }
                        $queue->push($creator_id);
                    }
                }
            }

            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage() . ' File:' . $e->getFile() . '; Line:' . $e->getLine() . ';');
        }
    }

    /**
     * 保存数据；
     * @param $data
     * @return string
     * @throws Exception
     */
    public function saveProductDetailData($data): string
    {
        $product = $data['product'];
        $detailList = $data['detailList'];

        $publishProductModel = new AmazonPublishProduct();
        $publishDetailModel = new AmazonPublishProductDetail();

        //需要返回的数据；
        $product_id = 0;

        //分成两种保存方式1.新增保存；
        if ($product['id'] == 0) {
            Db::startTrans();
            try {
                unset($product['id']);
                $product_id = $publishProductModel->insertGetId($product);
                if (empty($product_id)) {
                    if ($this->getLang() == 'zh') {
                        throw new Exception('保存失败');
                    } else {
                        throw new Exception('System Error');
                    }
                }
                foreach ($detailList as $detail) {
                    $detail['product_id'] = $product_id;
                    $publishDetailModel->insert($detail);
                }
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw new Exception($e->getMessage());
            }
            $task_id = AmazonPublishTask::where(['goods_id' => $product['goods_id'], 'account_id' => $product['account_id'], 'status' => ['<>', 2]])->value('id');
            if (!empty($task_id) && is_numeric($task_id)) {
                AmazonPublishTask::update(['product_id' => $product_id, 'status' => 1], ['id' => $task_id]);
            }

            return $product_id;
        }

        //2.修改保存以下是更新数据；先查出详情的数据；
        $parent_id = $publishDetailModel->where(['product_id' => $product['id'], 'type' => 0])->value('id');
        $oldDetailList = $publishDetailModel->where(['product_id' => $product['id'], 'type' => 1])->column('id', 'publish_sku');

        $product_id = $product['id'];
        //product_status,relation_status,quantity_status,image_status,price_status
        Db::startTrans();
        $update_product_status = false;
        try {
            foreach ($detailList as $detail) {
                $detail['product_id'] = $product['id'];
                if ($detail['type'] === 0) {
                    if (empty($parent_id)) {
                        $publishDetailModel->insert($detail);
                    } else {
                        //父元素在修改保存时没有带过publish_sku，把spu当做的publish_sku,所以在这里要unset掉防止把已有的数据修改了；
                        unset($detail['publish_sku']);
                        $publishDetailModel->update($detail, ['id' => $parent_id]);
                    }
                } else {
                    if (empty($oldDetailList[$detail['publish_sku']])) {
                        $publishDetailModel->insert($detail);
                        $update_product_status = true;
                    } else {
                        $publishDetailModel->update($detail, ['id' => $oldDetailList[$detail['publish_sku']]]);
                        unset($oldDetailList[$detail['publish_sku']]);
                    }
                }
            }

            //如果有新增SKU，那个纪录的产品状态应该是0
            if ($update_product_status) {
                $product['product_status'] = 0;
            }

            unset($product['id']);
            $publishProductModel->update($product, ['id' => $product_id]);

            //删掉多余的；
            if (!empty($oldDetailList)) {
                $publishDetailModel->where(['id' => ['in', array_values($oldDetailList)]])->delete();
            }

            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage() . $e->getFile() . $e->getLine());
        }

        return $product_id;
    }


    ///**
    // * 放进刊登队列；
    // * @param $param
    // */
    //public function toPublishQueue($param)
    //{
    //    $time = time();
    //    $queue = new UniqueQueuer(AmazonPublishProductQueuer::class);
    //
    //    $publishProductModel = new AmazonPublishProduct();
    //    $submissionModel = new AmazonpublishProductSubmission();
    //    $product = $publishProductModel->where(['id' => $param['id']])->field('id,account_id,timer')->find();
    //
    //    //定时时间小于当前时间
    //    if ($product['timer'] < $time + 20 * 60) {
    //        //查找一下有没有待
    //        $sdata = $submissionModel->where([
    //            'update_time' => ['>', $time - 12 * 3600],
    //            'account_id' => $product['account_id'],
    //            'type' => 1,
    //            'status'=> 0,
    //        ])->order('id', 'asc')->find();
    //
    //        if (!empty($sdata)) {
    //            $queue->push($sdata['id']);
    //        } else {
    //            $requestData = [
    //                'product_id' => $product['id'],
    //                'pids' => $product['id'],
    //                'type' => 1,
    //                'account_id' => $product['account_id'],
    //                'submission_id' => '',
    //                'ctron_time' => 0,
    //                'create_time' => $time,
    //            ];
    //            $sid = $submissionModel->insertGetId($requestData);
    //            $queue->push($sid);
    //        }
    //    }
    //}

    /**
     * 重新编辑接口；
     * @param $id
     * @param $type
     * @return bool
     * @throws Exception
     */
    public function reEdit($id, $type)
    {
        $pmodel = new AmazonPublishProduct();

        $product = $pmodel->get($id);
        if (!$product) {
            if ($this->lang == 'zh') {
                throw new Exception('产品ID为空，记录不存在');
            } else {
                throw new Exception('The product ID is empty and the record does not exist.');
            }
        }
        if (!$product['publish_status'] != AmazonPublishConfig::PUBLISH_STATUS_FINISH) {
            if ($this->lang == 'zh') {
                throw new Exception('只能用于重新编辑刊登完成的商品');
            } else {
                throw new Exception('Can only be used to reedit published items');
            }
        }

        switch ($type) {
            //修改对应关系
            case 'relation':
                $data = [
                    'upload_product' => AmazonPublishConfig::DETAIL_PUBLISH_STATUS_NONE,
                    'upload_relation' => AmazonPublishConfig::DETAIL_PUBLISH_STATUS_NONE
                ];
                break;

            //修改数量
            case 'quantity':
                $data = [
                    'upload_quantity' => AmazonPublishConfig::DETAIL_PUBLISH_STATUS_NONE,
                ];
                break;

            //修改图片
            case 'image':
                $data = [
                    'upload_image' => AmazonPublishConfig::DETAIL_PUBLISH_STATUS_NONE,
                ];
                break;

            //修改价格
            case 'price':
                $data = [
                    'upload_price' => AmazonPublishConfig::DETAIL_PUBLISH_STATUS_NONE,
                ];
                break;
            default:
                throw new Exception('未知类别');
        }
        $product->save(['publish_status' => AmazonPublishConfig::PUBLISH_STATUS_RE_EDIT]);
        $dmodel = new AmazonPublishProductDetail();
        $dmodel->update($data, ['product_id' => $id]);
        return true;
    }

    public function errorExport($ids)
    {
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }
        $ids = array_filter(array_unique($ids));

        $lists = AmazonPublishProduct::where(['id' => ['in', $ids]])->column('*', 'id');
        if (empty($lists)) {
            throw new Exception('导出参数对应的记录为空');
        }
        $dlists = AmazonPublishProductDetail::where(['product_id' => ['in', $ids]])
            ->field('product_id,sku,error_message,warning_message')
            ->select();
        $account_ids = [];
        $category_template_ids = [];

        foreach ($lists as $val) {
            $account_ids[] = $val['account_id'];
            $category_template_ids[] = $val['category_template_id'];
        }

        $accounts = AmazonAccount::where(['id' => ['in', $account_ids]])->column('code', 'id');
        $templates = AmazonXsdTemplate::where(['id' => ['in', $category_template_ids]])->column('name', 'id');

        //存放最终数据；
        $data = [];
        foreach ($dlists as $val) {
            if (empty($lists[$val['product_id']])) {
                continue;
            }
            $product = $lists[$val['product_id']];
            $tmp = [];
            $tmp['error_message'] = $this->buildMsg($val['error_message']);
            $tmp['warning_message'] = $this->buildMsg($val['warning_message']);
            if (empty($tmp['error_message']) && empty($tmp['warning_message'])) {
                continue;
            }
            $tmp['spu'] = $product['spu'];
            $tmp['sku'] = $val['sku'];
            $tmp['code'] = $accounts[$product['account_id']] ?? '-';

            $tmp['create_time'] = date('Y-m-d H:i', $product['create_time']);

            $data[] = $tmp;
        }

        try {
            $header = [
                ['title' => 'SPU', 'key' => 'spu', 'width' => 10],
                ['title' => 'SKU', 'key' => 'sku', 'width' => 10],
                ['title' => '帐号', 'key' => 'code', 'width' => 15],
                ['title' => '错误提示', 'key' => 'error_message', 'width' => 70],
                ['title' => '警告提示', 'key' => 'warning_message', 'width' => 70],
                ['title' => '创建时间', 'key' => 'create_time', 'width' => 20],
            ];

            $file = [
                'name' => 'Amazon刊登异常导出',
                'path' => 'amazon'
            ];
            $ExcelExport = new DownloadFileService();
            $result = $ExcelExport->export($data, $header, $file);
            return $result;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    private function buildMsg($msgArr)
    {
        if (empty($msgArr) || $msgArr == '[]' || json_decode($msgArr) === false) {
            return '';
        }
        $str = '';
        $arr = json_decode($msgArr, true);
        $fileds = ['upload_product' => '【产品】', 'upload_relation' => '【关系】', 'upload_price' => '【价格】', 'upload_quantity' => '【库存】', 'upload_image' => '【图片】'];
        foreach ($fileds as $key => $val) {
            if (!empty($arr[$key])) {
                $str .= $val . '：' . $arr[$key] . "\r\n\r\n";
            }
        }

        return $str;
    }


    /**
     * 批量复制；
     * @param $data
     */
    public function batchCopy($data)
    {
        $account_ids = explode(',', $data['account_ids']);
        $account_ids = array_filter($account_ids);

        $ids = explode(',', $data['ids']);
        $ids = array_filter($ids);
        if (empty($account_ids) || empty($ids)) {
            throw new Exception('帐号为空或者所复制的ID为空');
        }

        if (count($ids) > 100) {
            throw new Exception('单次复制最大允许复制100条');
        }

        $productModel = new AmazonPublishProduct();
        $detailModel = new AmazonPublishProductDetail();

        $productField = 'id,site,category_id,goods_id,doc_id,warehouse_id,spu,item_type,recommend_node,department,brand,save_map,theme_name,category_template_id,category_info,product_template_id,product_info,publish_status';
        $products = $productModel->where(['id' => ['in', $ids]])
            ->field($productField)
            ->select();
        $detailField = 'id,product_id,type,sku,product_id_type,condition_type,condition_note,standard_price,quantity,variant_info,part_number,recommend_node,main_image,swatch_image,other_image,title,search_Terms,bullet_point,description,binding_goods';
        $details = $detailModel->where(['product_id' => ['in', $ids]])
            ->field($detailField)
            ->order('id', 'asc')
            ->select();
        $new_products = [];
        $new_details = [];
        foreach ($products as $val) {
            if ($val['publish_status'] != AmazonPublishConfig::PUBLISH_STATUS_FINISH) {
                //throw new Exception('刊登记录SPU:'. $val['spu']. '状态不是刊登成功，不可以复制');
            }
            $new_products[$val['id']] = $val->toArray();
            foreach ($details as $dval) {
                if ($val['id'] == $dval['product_id']) {
                    $new_details[$val['id']][] = $dval->toArray();
                }
            }
        }

        $sites = AmazonCategoryXsdConfig::getSiteList();
        $sites = array_combine(array_column($sites, 'label'), array_column($sites, 'value'));

        $user = Common::getUserInfo();
        $time = time();

        $cache = Cache::store('AmazonAccount');
        foreach ($account_ids as $account_id) {
            $account = $cache->getAccount($account_id);
            if (empty($account)) {
                continue;
            }
            $site = $sites[strtoupper($account['site'])] ?? 0;
            //下面开始复制；
            foreach ($new_products as $id => $product) {
                if ($site != $product['site']) {
                    continue;
                }
                $product['account_id'] = $account_id;
                $product['creator_id'] = $user['user_id'];
                $product['create_time'] = $time;
                $product['update_time'] = $time;

                $tmpProduct = $this->buildCopyProduct($product, $site);
                $tmpDetails = $this->buildCopyProductDetail($new_details[$id]);
                try {
                    Db::startTrans();
                    $product_id = $productModel->insertGetId($tmpProduct);
                    foreach ($tmpDetails as $detail) {
                        $detail['product_id'] = $product_id;
                        $detailModel->insert($detail);
                    }
                    Db::commit();
                } catch (Exception $e) {
                    Db::rollback();
                    throw new Exception($e->getMessage());
                }
            }
        }

        return true;
    }


    /**
     * 组合成复制的产品数据；
     * @param $product
     * @param $site
     * @param $uid
     * @param $time
     * @return mixed
     */
    public function buildCopyProduct($product, $site)
    {
        unset($product['id']);
        //改状态为刊登草稿状态；
        $product['publish_status'] = AmazonPublishConfig::PUBLISH_STATUS_DRAFT;

        if ($site != $product['site']) {
            $product['site'] = $site;
            $product['recommend_node'] = '';
            $product['theme_name'] = '';
            $product['category_template_id'] = '';
            $product['product_template_id'] = '';
        }

        return $product;
    }


    /**
     * 组合成复制的产品详情数据；
     * @param $details
     * @param $product_id
     * @return array
     */
    public function buildCopyProductDetail($details)
    {
        $news = [];
        foreach ($details as $detail) {
            unset($detail['id']);
            $detail['publish_sku'] = $detail['sku'];
            $detail['error_message'] = '[]';
            $detail['warning_message'] = '[]';
            $news[] = $detail;
        }
        return $news;
    }
}