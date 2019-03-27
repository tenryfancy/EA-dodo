<?php
/**
 * Created by PhpStorm.
 * User: wuchuguang
 * Date: 17-2-21
 * Time: 下午3:51
 */

namespace app\goods\service;

use app\common\annotations\QueueType;
use app\common\service\Common;
use app\purchase\service\PurchaseOrder;
use app\warehouse\service\WarehouseGoods;
use app\order\service\OrderHelp;
use app\common\cache\Cache;
use app\common\cache\driver\Goods;
use app\common\model\GoodsSku as GoodsSkuModel;
use app\common\model\GoodsSkuAlias;
use erp\AbsServer;
use app\common\model\GoodsSkuAlias as GoodsSkuAliasModel;
use app\goods\service\GoodsSkuAlias as ServerGoodsSkuAlias;
use app\common\validate\GoodsSku as ValidateGoodsSku;
use app\common\model\Goods as ModelGoods;
use app\warehouse\service\Allocation;
use app\warehouse\service\Stocking;
use app\common\model\GoodsSkuRecycle;
use app\purchase\service\SupplierOfferService;
use think\Exception;
use think\Db;
use PDO;
use phpzip\PHPZip;
use think\db\Query;
use app\publish\queue\SkuCostQueue;
use app\publish\queue\GoodsPublishMapQueue;
use app\publish\queue\AliexpressLocalSellStatus;
use app\publish\queue\WishLocalSellStatus;
use app\publish\queue\PandaoLocalSellStatus;
use app\goods\service\GoodsImage;
use app\common\service\CommonQueuer;
use app\common\service\UniqueQueuer;
use app\goods\service\GoodsNotice;
use app\goods\service\GoodsHelp;
use app\index\service\DownloadFileService;
use app\report\model\ReportExportFiles;
use app\publish\service\AmazonPublishHelper;
use app\listing\service\AliexpressListingHelper;
use app\publish\helper\ebay\EbayPublish;
use app\publish\service\WishHelper;
use app\publish\helper\shopee\ShopeeHelper;
use app\publish\service\JoomService;
use app\publish\service\PandaoService;
use app\internalletter\service\InternalLetterService;
use app\order\service\OrderRuleExecuteService;
use app\goods\queue\GoodsSkuAfterUpdateQueue;
use app\warehouse\service\Warehouse;
use app\publish\queue\EbaySkuLocalStatusChange;
use app\common\service\Excel;
use app\warehouse\queue\SkuStopSaleUnbindCargoQueue;
use app\goods\queue\GoodsSkuBatchStoppedQueue;

class GoodsSku extends AbsServer
{
    public function querySku($keyword, $size = 15)
    {
        if (!$keyword = trim($keyword)) {
            return [];
        }
        $model = new GoodsSkuModel();
        $aliasModel = new GoodsSkuAlias();
        $aliasData = $aliasModel->field('sku_code')->where(['alias' => $keyword])->find();
        if (!empty($aliasData)) {
            $keyword = $aliasData['sku_code'];
        }
        $model->where('sku', 'like', "$keyword%");
        $model->field('sku');
        $skus = $model->page(1, $size)->select();
        $result = [];
        foreach ($skus as $sku) {
            $result[] = $sku['sku'];
        }
        return $result;
    }

    /**
     * @QueueType()
     * @param $keyword
     * @param int $size
     * @return array
     */
    public function querySkus($keyword, $size = 15)
    {
        $keyword = str_replace(' ', ';', $keyword);
        $keyword = str_replace(',', ';', $keyword);
        $keywords = explode(';', $keyword);
        $arrs = [];
        foreach ($keywords as $keyword) {
            $arr = $this->querySku($keyword, $size);
            $arrs = array_merge($arrs, $arr);
        }
        return array_values(array_unique($arrs));
    }

    /**
     * @doc 根据skuId获取goodsSku
     * @param $skuId
     * @return GoodsSkuModel | false;
     */
    public static function getBySkuID($skuId, $width = 100, $height = 100)
    {
        /**
         * @var $cache Goods
         */
        $cache = Cache::store('goods');
        return $cache->getSkuInfo($skuId, $width, $height);
    }

    /**
     * 根据skuId获取goodsSku 别名
     * @param number $skuId
     */
    function getAliasName($skuId = 0)
    {
        //this function add by tb   相关人员后期可修改成：使用缓存
        $info = GoodsSkuAliasModel::field('alias')->where(['sku_id' => $skuId, 'type' => 2])->find();
        return $info['alias'];
    }

    /**
     * 判断是否已存在相同属性
     * @param $sku
     * @param $skuAttributes
     * @return bool
     * @author starzhan <397041849@qq.com>
     */
    public function isSameSkuAttributes($goods_id, $skuAttributes)
    {
        $o = GoodsSkuModel::where('goods_id', $goods_id);
        $askuAttributes = json_decode($skuAttributes, true);
        foreach ($askuAttributes as $k => $val) {
            $o = $o->where('sku_attributes$.' . $k, $val);
        }
        $count = $o->count();
        if ($count) {
            return true;
        }
        return false;
    }

    /**
     * 根据sku获取id
     * @param $sku
     * @return int|mixed
     * @autor starzhan <397041849@qq.com>
     */
    public function getSkuIdBySku($sku)
    {
        $sku = trim($sku);
        $sku = preg_replace("/(\s|\&nbsp\;|　|\xc2\xa0)/", "", $sku);
        if (!$sku) {
            return 0;
        }
        $oAlias = GoodsSkuAlias::where('alias', $sku)->find();
        if ($oAlias) {
            return $oAlias->sku_id;
        }
        return 0;
    }

    /**
     * @title 根据别名或sku，返出skuID
     * @author starzhan <397041849@qq.com>
     */
    public function getASkuIdByASkuOrAlias($aSku)
    {
        if (!$aSku) {
            return [];
        }
        if (!is_array($aSku)) {
            $aSku = [$aSku];
        }
        $result = [];
        foreach ($aSku as &$sku) {
            $sku = trim($sku);
            $sku = preg_replace("/(\s|\&nbsp\;|　|\xc2\xa0)/", "", $sku);
        }

        $aDiff = GoodsSkuAlias::where('alias', 'in', $aSku)->field('alias,sku_id')->select();
        foreach ($aDiff as $v) {
            $result[$v['alias']] = $v['sku_id'];
        }
        return $result;
    }

    public function sequence($sku_id, $num)
    {
        $GoodsSkuModel = new GoodsSkuModel();
        $skuInfo = $GoodsSkuModel->where('id', $sku_id)->find();
        $oldNum = $skuInfo->sequence;
        $skuInfo->sequence = $skuInfo->sequence + $num;
        $skuInfo->save();
        return $oldNum;
    }

    /**
     * @title 注释.
     * @param $sku string 可能是别名/id/sku
     * @author starzhan <397041849@qq.com>
     */
    public function getSkuInfo($sku, $type = [2])
    {
        $id = $this->getSkuIdBySku($sku);
        if (!$id) {
            throw new Exception('该sku无法识别');
        }
        $result = self::getBySkuID($id, 200, 200);
        $goods_id = $result['goods_id'];
        $aGoods = Cache::store('goods')->getGoodsInfo($goods_id);
        $result['spu_name'] = $aGoods['name'];
        $types = ['in', $type];
        $result['sku_alias'] = ServerGoodsSkuAlias::getAliasBySkuId($id, $types);
        return $result;
    }

    /**
     * @title 根据全文索引来检索
     * @author starzhan <397041849@qq.com>
     */
    public function getSkuIdByKeyFullTxtIndex($aKey = [], $isLike = 0)
    {
        $ModelGoods = new ModelGoods();
        if (!$aKey) {
            throw new Exception('参数不能为空');
        }
        $fullTxt = [];
        foreach ($aKey as $k) {
            if ($isLike) {
                $k = preg_replace("/^%(.*)/", '*$1', $k);
                $k = preg_replace("/(.*)%$/", '$1*', $k);
            }
            $fullTxt[] = "+" . $k;
        }
        $tmp = $ModelGoods->field('id')->where("match(name) against('" . implode(' ', $fullTxt) . "' IN BOOLEAN MODE)")->select();
        $aGoodsId = [];
        foreach ($tmp as $v) {
            $aGoodsId[] = $v['id'];
        }
        $result = [];
        if ($aGoodsId) {
            $ret = GoodsSkuModel::where('goods_id', 'in', $aGoodsId)->field('id')->select();
            foreach ($ret as $v) {
                $result[] = $v['id'];
            }
        }
        return $result;
    }

    public function convertAttr($row)
    {
        $attributes = [];
        $message = '';
        foreach ($row['attributes'] as $arr) {
            $value_id = $arr['value_id'];
            $value = $arr['value'] ?? '';
            $attribute_id = $arr['attribute_id'];
            try {
                if (!$value_id && in_array($attribute_id, GoodsImport::$diy_attr) && !empty($value)) {
                    $attributes[] = [
                        'attribute_id' => $attribute_id,
                        'value' => $value,
                        'value_id' => 0
                    ];
                } else {
                    $attributes[] = [
                        'attribute_id' => $attribute_id,
                        'value_id' => $value_id
                    ];
                }
            } catch (Exception $ex) {
                $message .= $ex->getMessage();
            }
        }
        if ($message) {
            throw new Exception($message);
        }
        return $attributes;
    }

    private function getAlias($skuId)
    {
        $GoodsSkuAliasServer = new ServerGoodsSkuAlias();
        $aList = $GoodsSkuAliasServer->getListBySkuId($skuId);
        $result = ['all' => [], 'type2' => []];
        foreach ($aList as $v) {
            $result['all'][] = $v['alias'];
            if (in_array($v['type'], [2])) {
                $result['type2'][] = $v['alias'];
            }
        }
        return $result;
    }

    /**
     * @title 注释..
     * @param $id
     * @param $list
     * @param $user_id
     * @param callable|null $addCallback
     * @param string $where 使用场景
     * @return array
     * @throws Exception
     * @author starzhan <397041849@qq.com>
     */
    public function saveSkuInfo($id, $list, $user_id, callable $addCallback = null, $where = 'goods')
    {
        $GoodsHelp = new GoodsHelp();
        $aGoods = $GoodsHelp->getGoodsInfo($id);
        if (!$aGoods) {
            throw new Exception('不存在商品');
        }
        $ModelGoodsSku = new GoodsSkuModel();
        $aBaseSku = $ModelGoodsSku->where('goods_id', $id)->select();
        $allAttr = [];
        $oldAttr = [];
        $add = [];
        $oldSku = [];
        $oldAlias = [];
        foreach ($aBaseSku as $skuInfo) {
            $attrInfo = json_decode($skuInfo['sku_attributes'], true);
            $arrTmp = [];
            foreach ($attrInfo as $key => $value) {
                $aKey = explode('_', $key);
                $arrTmp[$aKey[1]] = $value;
            }
            ksort($arrTmp, SORT_NUMERIC);
            $oldAttr[$skuInfo['id']] = json_encode($arrTmp, true);
            $oldSku[$skuInfo['id']] = $skuInfo;

            $oldAlias[$skuInfo['id']] = $this->getAlias($skuInfo['id']);
            sort($oldAlias[$skuInfo['id']]['all'], SORT_STRING);
            sort($oldAlias[$skuInfo['id']]['type2'], SORT_STRING);
        }
        $oldId = array_keys($oldAttr);
        $postData = [];
        $postAlias = [];//提交的别名
        $sku_array = [];
        $listAttr = [];
        $tmp = [];
        $tmp['category_id'] = $aGoods['category_id'];
        $tmp['goods_id'] = $id;
        $attributes = $GoodsHelp->getAttributeInfo($id, 1);
        $goods_attributes = [];
        foreach ($attributes as $attribute) {
            $values = [];
            foreach ($attribute['attribute_value'] as $k => $vList) {
                $values[$vList['id']] = $vList;
            }
            $attribute['attribute_value'] = $values;
            $goods_attributes[$attribute['attribute_id']] = $attribute;
        }

        if (isset($goods_attributes[11])) {
            $goods_attributes[11]['attribute_value'] = [];
        }
        if (isset($goods_attributes[15])) {
            $goods_attributes[15]['attribute_value'] = [];
        }
        foreach ($list as $v) {
            $tmpAttr = [];
            $aTmpAttr = [];
            $v['attr_info'] = $this->convertAttr($v);
            foreach ($v['attr_info'] as $attr) {
                $tmp['attribute_id'] = $attr['attribute_id'];
                $tmp['value_id'] = $attr['value_id'];
                $tmp['value'] = $attr['value'] ?? '';
                if ($attr['value_id'] == 0) {
                    $value_id = GoodsImport::addSelfAttribute($tmp);
                    $tmpAttr['attr_' . $attr['attribute_id']] = $value_id;
                    $aTmpAttr[$attr['attribute_id']] = $value_id;
                } else {
                    GoodsImport::addAttribute($tmp);
                    $tmpAttr['attr_' . $attr['attribute_id']] = $attr['value_id'];
                    $aTmpAttr[$attr['attribute_id']] = $attr['value_id'];
                }
            }
            $v['sku_attributes'] = json_encode($tmpAttr, true);
            if ($v['id']) {
                $listAttr[$v['id']] = json_encode($aTmpAttr, true);
            }
            if (empty($v['id'])) {
                if (empty($v['sku'])) {
                    $v['sku'] = $GoodsHelp->createSku($aGoods['spu'], [], $id, 0, $sku_array);
                }
                $sku_array[] = $v['sku'];
                $add[] = $v;
                $oldAttr[] = json_encode($aTmpAttr, true);
            } else {
                $postData[$v['id']] = $v;
                $oldAttr[$v['id']] = json_encode($aTmpAttr, true);
                sort($v['alias_sku'], SORT_STRING);
                $postAlias[$v['id']] = $v['alias_sku'];
            }
        }
        $mdfAlias = [];
        foreach ($postAlias as $skuId => $v) {
            if ($v !== $oldAlias[$skuId]['type2']) {
                $mdfAlias[$skuId] = $v;
            }
        }
        $unique_arr = array_unique($oldAttr);
        // 获取重复数据的数组
        $repeat_arr = array_diff_assoc($oldAttr, $unique_arr);

        if ($repeat_arr) {
            throw new Exception('属性重复，不允许保存');
        }
        $mdfId = array_intersect($oldId, array_keys($postData));
        if (empty($add) && empty($mdfId) && empty($mdfAlias)) {
            throw new Exception('没什么可操作的事情');
        }
        Db::startTrans();
        try {
            $GoodsLog = new GoodsLog();
            if ($add) {
                foreach ($add as $v) {
                    $v['goods_id'] = $id;
                    $v['thumb'] = '';
                    $v['spu_name'] = $aGoods['name'];
                    $ModelGoodsSku = new GoodsSkuModel();
                    $ValidateGoodsSku = new ValidateGoodsSku();
                    $scene = 'add';
                    if ($where == 'dev') {
                        $scene = 'dev';
                    }
                    $flag = $ValidateGoodsSku->scene('add')->check($v);
                    if ($flag === false) {
                        throw new Exception($ValidateGoodsSku->getError());
                    }
                    $v['length'] = $v['length'] * 10;
                    $v['width'] = $v['width'] * 10;
                    $v['height'] = $v['height'] * 10;
                    if (isset($v['is_auto_update_weight']) && $v['is_auto_update_weight'] == 1) {
                        $v['auto_update_time'] = time();
                    }
                    $v['old_weight'] = $v['weight'];
                    $ModelGoodsSku->allowField(true)->isUpdate(false)->save($v);
                    $GoodsLog->addSku($v['sku']);
                    $GoodsSkuAlias = new ServerGoodsSkuAlias();
                    $GoodsSkuAlias->insert($ModelGoodsSku->id, $v['sku'], $v['sku'], 1);
                    if (!empty($v['alias_sku'])) {
                        foreach ($v['alias_sku'] as $alias) {
                            $GoodsSkuAlias = new ServerGoodsSkuAlias();
                            $GoodsSkuAlias->insert($ModelGoodsSku->id, $v['sku'], $alias, 2);
                        }
                    }
                    if ($addCallback) {
                        $v['id'] = $ModelGoodsSku->id;
                        $addCallback($v);
                    }

                }
            }
            foreach ($mdfId as $id) {
                $skuInfo = $postData[$id];
                if (isset($skuInfo['is_auto_update_weight']) && $skuInfo['is_auto_update_weight'] == 1) {
                    $oldSkuInfo = $oldSku[$id];
                    if ($oldSkuInfo['auto_update_time']) {
                        $skuInfo['auto_update_time'] = 0;
                    } else {
                        $skuInfo['auto_update_time'] = time();
                    }
                }
                if (isset($skuInfo['length']) && $skuInfo['length']) {
                    $skuInfo['length'] = $skuInfo['length'] * 10;
                }
                if (isset($skuInfo['width']) && $skuInfo['width']) {
                    $skuInfo['width'] = $skuInfo['width'] * 10;
                }
                if (isset($skuInfo['height']) && $skuInfo['height']) {
                    $skuInfo['height'] = $skuInfo['height'] * 10;
                }
                if (isset($skuInfo['weight'])) {
                    if ($skuInfo['weight'] != $oldSku[$id]['weight']) {
                        $skuInfo['old_weight'] = $oldSku[$id]['weight'];
                    }
                }
                $ModelGoodsSku = new GoodsSkuModel();
                $ModelGoodsSku->allowField(true)->isUpdate(true)->save($skuInfo, ['id' => $id]);
                $this->afterUpdate($oldSku[$id], $skuInfo);
                Cache::handler()->hdel('cache:Sku', $id);
                $GoodsLog->mdfSku($skuInfo['sku'], $oldSku[$id], $skuInfo);
            }
            foreach ($mdfAlias as $skuId => $v) {
                $skuInfo = $postData[$skuId];
                $this->saveAlias($skuId, $skuInfo['sku'], $v);
            }
            if ($where != 'dev') {
                $GoodsLog->save($user_id, $aGoods['id']);
            }
            Db::commit();
            return ['message' => '保存成功'];
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }
        $delId = array_diff($oldId, array_keys($postData));
    }

    private function saveAlias($sku_id, $sku, $aAlias)
    {
        $GoodsSkuAliasServer = new ServerGoodsSkuAlias();
        $aList = $GoodsSkuAliasServer->getListBySkuId($sku_id, ['in', [2]]);
        $oldAlias = [];
        foreach ($aList as $v) {
            $oldAlias[] = (string)$v['alias'];
            if (!in_array($v['alias'], $aAlias,true)) {
                $GoodsSkuAlias = new GoodsSkuAliasModel();
                $GoodsSkuAlias->where('sku_id', $sku_id)->where('alias', $v['alias'])->delete();
            }
        }
        foreach ($aAlias as $new) {
            if (!in_array($new, $oldAlias, true)) {
                $GoodsSkuAlias = new ServerGoodsSkuAlias();
                $flag = $GoodsSkuAlias->insert($sku_id, $sku, $new, 2);
                if (!$flag) {
                    throw new Exception($new . "已被占用");
                }
            }
        }

    }

    public function getAttrTextByAttr($sku_attr, $goods_id)
    {
        $attr = json_decode($sku_attr, true);
        $attrs = GoodsHelp::getAttrbuteInfoBySkuAttributes($attr, $goods_id);
        $tmp = [];
        foreach ($attrs as $val) {
            $tmp[] = $val['value'];
        }
        return implode(' ；', $tmp);
    }

    public function getSkuSiblings($sku_id)
    {
        $skuInfo = self::getBySkuID($sku_id);
        if (!$skuInfo) {
            throw new Exception('该sku不存在');
        }
        $result = [];
        $tmp = GoodsSkuModel::where(['goods_id' => $skuInfo['goods_id']])->field('id,sku,sku_attributes')->select();
        foreach ($tmp as $v) {
            $row = [];
            $row['id'] = $v->id;
            $row['sku'] = $v->sku;
            $row['attr'] = $this->getAttrTextByAttr($v->sku_attributes, $skuInfo['goods_id']);
            $result[] = $row;
        }
        return $result;
    }

    /**
     * @title 根据spu查skuid
     * @param $spu
     * @return array
     * @author starzhan <397041849@qq.com>
     */
    public function getSkuIdBySpu($spu)
    {
        $spu = trim($spu);
        if (!$spu) {
            return [];
        }
        $GoodsHelp = new GoodsHelp();
        $result = $GoodsHelp->spu2goodsId($spu);
        if (!$result) {
            return [];
        }
        $goodsId = reset($result);
        $tmp = GoodsSkuModel::where(['goods_id' => $goodsId])->field('id')->select();
        $ret = [];
        foreach ($tmp as $v) {
            $ret[] = $v['id'];
        }
        return $ret;

    }

    public function getSkuIdByLikeSku($sku)
    {
        $sku = trim($sku);
        $sku = preg_replace("/(\s|\&nbsp\;|　|\xc2\xa0)/", "", $sku);
        if (!$sku) {
            return [];
        }
        if (preg_match("/^%.*/", $sku) || preg_match("/.*%$/", $sku)) {
            $where = ['alias' => ['like', $sku], 'type' => 1];
        } else {
            $where = ['alias' => $sku, 'type' => 1];
        }
        $sku_ids = GoodsSkuAlias::where($where)->column('sku_id');
        if ($sku_ids) {
            return $sku_ids;
        }
        return [];
    }

    public function delete($ids)
    {
        $err = [];
        $i = 0;
        $e = 0;
        foreach ($ids as $id) {
            $GoodsSkuModel = new GoodsSkuModel();
            $aGoodsSku = $GoodsSkuModel->where('id', 'in', $id)->find();
            if (!$aGoodsSku) {
                throw new Exception('该sku不存在!');
            }
            $WarehouseGoods = new WarehouseGoods();
            $WarehouseGoods->validateDeleteSku($id);
            $OrderHelp = new OrderHelp();
            $flag = $OrderHelp->isHasSku($id);
            if ($flag) {
                throw new Exception('该sku已被订单系统使用，无法删除');
            }
            $PurchaseOrder = new PurchaseOrder();
            $PurchaseOrder->validateDeleteSku($id);
            $Allocation = new Allocation();
            $Allocation->validateDeleteSku($id);
            $Stocking = new Stocking();
            $Stocking->validateDeleteSku($id);
            Db::startTrans();
            try {
                $userInfo = Common::getUserInfo();
                $data = $aGoodsSku->getData();
                $data['sku_id'] = $data['id'];
                $data['deleted_time'] = time();
                $data['deleted_id'] = $userInfo['user_id'];
                GoodsSkuAlias::where('sku_id', $data['id'])->delete();
                unset($data['id']);
                $GoodsSkuRecycle = new GoodsSkuRecycle();
                $GoodsSkuRecycle->allowField(true)->isUpdate(false)->save($data);
                $aGoodsSku->delete();
                $GoodsLog = new GoodsLog();
                $GoodsLog->delSku($data['sku']);
                $GoodsLog->save($userInfo['user_id'], $data['goods_id']);
                $i++;
                Db::commit();
            } catch (Exception $ex) {
                $e++;
                Db::rollback();
                $err[] = $ex->getMessage();
            }

        }
        $msg = '删除成功' . $i . '条数据,删除失败' . $e . '条数据,失败原因:' . implode(',', $err);
        return ['message' => $msg];

    }

    /**
     * @title 注释..
     * @param $goods_id
     * @param string $field
     * @return false|\PDOStatement|string|\think\Collection
     * @author starzhan <397041849@qq.com>
     */
    public static function getSkuByGoodsId($goods_id, $field = '*')
    {
        return GoodsSkuModel::where('goods_id', $goods_id)->field($field)->select();
    }

    public function updateCostPriceQueue($sku_id, $cost_price, $user_id)
    {
        $GoodsSku = new GoodsSkuModel();
        $aGoodsSku = $GoodsSku->where('id', $sku_id)->find();
        if (!$aGoodsSku) {
            throw new Exception('该sku不存在');
        }
        Db::startTrans();
        try {
            $GoodsLogs = new GoodsLog();
            $oldPrice = $aGoodsSku->cost_price;
            $GoodsLogs->mdfSku($aGoodsSku->sku, ['cost_price' => $oldPrice], ['cost_price' => $cost_price]);
            $old = $aGoodsSku->toArray();
            $aGoodsSku->cost_price = $cost_price;
            $aGoodsSku->save();
            $GoodsLogs->save($user_id, $aGoodsSku->goods_id, '[cost_price_update]');
            $this->afterUpdate($old, ['cost_price' => $cost_price]);
            Db::commit();
//            $this->afterUpdateCostPrice($sku_id, $oldPrice, $cost_price);
        } catch (Exception $ex) {
            Db::rollback();
            throw  $ex;
        }

    }

    public function afterUpdateDefSupplier($SupplierId, $goods_id, &$GoodsLog)
    {
        $SupplierOfferService = new SupplierOfferService();
        $supplierGoods = $SupplierOfferService->getOffer($goods_id, $SupplierId);
        $OrderRuleExecuteService = new OrderRuleExecuteService();
        foreach ($supplierGoods as $v) {
            $skuId = $v['sku_id'];
            $price = $v['audited_price'];
            $currency_code = $v['currency_code'] ?? 'CNY';
            if ($currency_code != 'CNY') {
                $price = $OrderRuleExecuteService->convertCurrency($currency_code, 'CNY', $v['audited_price']);
            }
            $GoodsSku = new GoodsSkuModel();
            $aSkuInfo = $GoodsSku->where('id', $skuId)->find();
            if ($aSkuInfo) {
                if (floatval($aSkuInfo['cost_price']) != floatval($price)) {
                    $old = $aSkuInfo->toArray();
                    $oldPrice = $aSkuInfo->cost_price;
                    $GoodsLog->mdfSku($aSkuInfo->sku, ['cost_price' => $oldPrice], ['cost_price' => $price]);
                    $aSkuInfo->cost_price = $price;
                    $aSkuInfo->save();
                    $this->afterUpdate($old, ['cost_price' => $price]);
                }
            }

        }
    }

    /**
     * 检测的字段
     */
    const AFTER_UPDATE_METHODS = [
        'cost_price',
        'status'

    ];

    /**
     * @title 修改后触发
     * @param $old
     * @param $new
     * @author starzhan <397041849@qq.com>
     */
    public function afterUpdate($old, $new)
    {
        $queue = new CommonQueuer(GoodsSkuAfterUpdateQueue::class);
        if (is_object($old)) {
            $old = $old->toArray();
        }
        $data = [
            'old' => $old,
            'new' => $new
        ];
        $queue->push($data);
    }

    public function doAfterUpdate($old, $new)
    {
        $GoodsSkuModel = new GoodsSkuModel();
        $fields = $GoodsSkuModel->getFields();
        foreach ($new as $field => $val) {
            if (!in_array($field, $fields)) {
                continue;
            }
            if ($val != $old[$field]) {
                $mdfField = $field;
                if (in_array($mdfField, self::AFTER_UPDATE_METHODS)) {
                    $method = $this->getAfterMethod($mdfField);
                    $this->{$method}($old, $new);
                }
            }
        }
    }

    private function afterUpdateStatus($old, $new)
    {
        $skuId = $old['id'];
        $oldstatus = $old['status'];
        $newstatus = $new['status'];
        $this->pushSkuStatusQueue($skuId, $newstatus);
        if ($newstatus == 2) {
            $this->sendNotice($old);
            $this->push2PublishToDown($old);
        } elseif ($newstatus == 4) {
            $this->push2PublishToDown($old);
        }
    }

    private function push2PublishToDown($old)
    {
        $GoodsHelp = new GoodsHelp();
        $goodsInfo = $GoodsHelp->getGoodsInfo($old['goods_id']);
        if (!$goodsInfo) {
            return false;
        }
        if ($goodsInfo['is_multi_warehouse'] == 1) {
            return false;
        }
        $Warehouse = new Warehouse();
        $isLocal = $Warehouse->isLocal($goodsInfo['warehouse_id']);
        if (!$isLocal) {
            return false;
        }
        $WarehouseGoods = new WarehouseGoods();
        $qty = $WarehouseGoods->available_quantity($goodsInfo['warehouse_id'], $old['id']);
        if ($qty == 0) {
            $queue = new CommonQueuer(EbaySkuLocalStatusChange::class);
            $queue->push($old['id']);
        }
    }


    public function sendNotice($old)
    {
        $sku = $old['sku'];
        $alias = ServerGoodsSkuAlias::getAliasBySkuId($old['id']);
        if ($alias) {
            $sku .= "[" . implode(',', $alias) . "]";
        }
        GoodsNotice::downSku($sku);
    }

    public function pushSkuStatusQueue($sku_id, $status)
    {
        $data = [
            'id' => $sku_id,
            'status' => $status,
            'type' => 2
        ];
        $queu1 = new CommonQueuer(AliexpressLocalSellStatus::class);
        $queu2 = new CommonQueuer(WishLocalSellStatus::class);
        $queu3 = new CommonQueuer(PandaoLocalSellStatus::class);
        // $data = json_encode($data);
        $queu1->push($data);
        $queu2->push($data);
        $queu3->push($data);
        return true;
    }


    private function getAfterMethod($mdfField)
    {
        $arr = explode('_', $mdfField);
        $method = 'afterUpdate';
        foreach ($arr as $work) {
            $method .= ucfirst($work);
        }
        if (!method_exists($this, $method)) {
            throw new Exception(__FILE__ . "不存在该方法");
        }
        return $method;
    }

    /**
     * @title 注释..
     * @param $skuId
     * @param $oldPrice
     * @param $newPrice
     * @author starzhan <397041849@qq.com>
     */
    public function afterUpdateCostPrice($old, $new)
    {
        $skuId = $old['id'];
        $oldPrice = floatval($old['cost_price']);
        $newPrice = floatval($new['cost_price']);
        $queue = new CommonQueuer(SkuCostQueue::class);
        $pushData = [];
        $pushData['sku_id'] = $skuId;
        $pushData['sku_cost'] = $newPrice;
        $pushData['sku_pre_cost'] = $oldPrice;
        $queue->push($pushData);
        $title = 'sku成本价更新通知';
        $content = "SKU：{$old['sku']}，成本价由：{$oldPrice} 改为 {$newPrice}";
        $this->send2PublishUser($skuId, $title, $content);
    }

    public function afterUpdateWeight($old, $new)
    {
        $skuId = $old['id'];
        $old = intval($old['weight']);
        $new = intval($new['weight']);
        $title = 'sku成本价更新通知';
        $content = "SKU：{$old['sku']}，重量由：{$old}g 改为 {$new}g";
        $this->send2PublishUser($skuId, $title, $content);
    }

    /**
     * @title 给相关已刊登过该sku的人发钉钉
     * @param $sku_id
     * @param $title
     * @param $content
     * @author starzhan <397041849@qq.com>
     */
    private function send2PublishUser($sku_id, $title, $content)
    {
        $ser = new AmazonPublishHelper();
        $users = $ser->getSellerIdBySku($sku_id);
        $AliExpressSer = new AliexpressListingHelper();
        $AliExpressUsers = $AliExpressSer->getSellerIdBySku($sku_id);
        if ($AliExpressUsers) {
            $users = array_merge($users, $AliExpressUsers);
        }
        $ebayUser = EbayPublish::getSalesmenBySkuId($sku_id);
        $wishUser = WishHelper::getSalesmenBySkuId($sku_id);
        $shopeeUser = ShopeeHelper::getSalesmenBySkuId($sku_id);
        $joomUser = JoomService::getSalesmenBySkuId($sku_id);
        $pandaoUser = PandaoService::getSalesmenBySkuId($sku_id);
        if ($ebayUser) {
            $users = array_merge($users, $ebayUser);
        }
        if ($wishUser) {
            $users = array_merge($users, $wishUser);
        }
        if ($shopeeUser) {
            $users = array_merge($users, $shopeeUser);
        }
        if ($joomUser) {
            $users = array_merge($users, $joomUser);
        }
        if ($pandaoUser) {
            $users = array_merge($users, $pandaoUser);
        }
        $user_ids = [];
        foreach ($users as $userId) {
            $user_ids[] = $userId;
        }
        if ($user_ids) {
            $user_ids = array_filter($user_ids);
            $user_ids = array_unique($user_ids);
            $params = [
                'receive_ids' => $user_ids,
                'title' => $title,
                'content' => $content,
                'type' => 2,
                'dingtalk' => 1
            ];
            InternalLetterService::sendLetter($params);
        }
    }

    /**
     * @title 根据skuId获取默认供应商
     * @param $skuId
     * @return mixed
     * @throws Exception
     * @author starzhan <397041849@qq.com>
     */
    public function getDefaultSupplierId($skuId)
    {
        $skuInfo = Cache::store('goods')->getSkuInfo($skuId);
        if (!$skuInfo) {
            return '';
        }
        $goods_id = $skuInfo['goods_id'];
        $goodsInfo = Cache::store('goods')->getGoodsInfo($goods_id);
        if (!$goodsInfo) {
            return '';
        }
        $Model = new ModelGoods();
        return $Model->getSupplierAttr(null, ['supplier_id' => $goodsInfo['supplier_id']]);
    }

    /**
     * @title 批量修改 重量
     * @param array $data ['id'=>'weight']
     * @author starzhan <397041849@qq.com>
     */
    public function batchUpdateWeightByASkuId($data = [], $resource = '【包裹重量回写】')
    {
        $skuId = array_keys($data);
        if ($skuId) {
            $aSku = GoodsSkuModel::where('id', 'in', $skuId)->field('id,goods_id,sku,weight,auto_update_time,old_weight')->select();
            if ($aSku) {
                foreach ($aSku as $skuInfo) {
                    $weight = $data[$skuInfo->id];
                    $GoodsLog = new GoodsLog();
                    $old = $skuInfo->toArray();
                    $new = [];
                    if ($weight != $skuInfo->weight) {
                        $skuInfo->old_weight = $skuInfo->weight;
                        $skuInfo->weight = $weight;
                        $new['weight'] = $weight;
                        $new['old_weight'] = $skuInfo->old_weight;

                    }
                    $skuInfo->auto_update_time = time();
                    $new['auto_update_time'] = $skuInfo->auto_update_time;
                    Db::startTrans();
                    try {
                        $skuInfo->save();
                        $this->afterUpdate($old, $new);
                        $GoodsLog->mdfSku($old['sku'], $old, $new);
                        $GoodsLog->save(Common::getUserInfo()['user_id'], $old['goods_id'], $resource);
                        Db::commit();
                        Cache::store('goods')->delSkuInfo($skuInfo->id);
                    } catch (Exception $e) {
                        Db::rollback();
                        //throw $e;
                    }
                }
            }
        }
    }

    /**
     * @title 批量修改 重量
     * @param array $data ['id'=>'weight']
     * @author starzhan <397041849@qq.com>
     */
    public function updateSizeByASkuId($skuId, $data = [], $resource = '【包裹尺寸回写】')
    {

        if ($skuId && $data) {

            $skuInfo = GoodsSkuModel::where('id', $skuId)->field('id,goods_id,sku,width,height,length,auto_update_size_time')->find();
            if ($skuInfo) {

                $GoodsLog = new GoodsLog();
                $old = $skuInfo->toArray();
                $new = [];
                if (isset($data['length'])) {
                    if ($old['length'] != $data['length']) {
                        $skuInfo->length = $data['length'];
                        $new['length'] = $data['length'];
                    }
                }
                if (isset($data['width'])) {
                    if ($old['width'] != $data['width']) {
                        $skuInfo->width = $data['width'];
                        $new['width'] = $data['width'];
                    }
                }
                if (isset($data['height'])) {
                    if ($old['height'] != $data['height']) {
                        $skuInfo->height = $data['height'];
                        $new['height'] = $data['height'];
                    }
                }
                if ($new) {
                    $skuInfo->auto_update_size_time = time();
                    $new['auto_update_size_time'] = $skuInfo->auto_update_size_time;
                    Db::startTrans();
                    try {
                        $skuInfo->save();
                        $this->afterUpdate($old, $new);
                        $GoodsLog->mdfSku($old['sku'], $old, $new);
                        $GoodsLog->save(Common::getUserInfo()['user_id'], $old['goods_id'], $resource);
                        Db::commit();
                        Cache::store('goods')->delSkuInfo($skuInfo->id);
                    } catch (Exception $e) {
                        Db::rollback();
                    }
                }

            }
        }

    }

    public function diffWeightWhere($param)
    {
        $o = new GoodsSkuModel();
        if (isset($param['snType']) && isset($param['snText']) && !empty($param['snText'])) {
            switch ($param['snType']) {
                case 'sku':
                    $arrValue = json_decode($param['snText'], true);
                    if ($arrValue) {
                        if (count($arrValue) == 1) {
                            $arrValue = reset($arrValue);
                            $sku_id = GoodsHelp::getSkuIdByAlias($arrValue);
                            $o = $o->where('id', $sku_id);
                        } else {
                            $skuIds = $this->getASkuIdByASkuOrAlias($arrValue);
                            $skuIds = array_values($skuIds);
                            if ($skuIds) {
                                $o = $o->where('id', 'in', $skuIds);
                            } else {
                                $o = $o->where('id', '-1');
                            }
                        }
                    }
            }
        }
        if (isset($param['is_auto_update_weight'])) {
            if ($param['is_auto_update_weight'] == 1) {
                $o = $o->where('auto_update_time', '0');
            } else {
                $o = $o->where('auto_update_time', '>', '0');
            }
        }
        if (isset($param['weight_st']) && $param['weight_st'] !== '') {
            $weight_st = $param['weight_st'];
            $o = $o->where('weight-old_weight', '>=', $weight_st);
        }
        if (isset($param['weight_nd']) && $param['weight_nd'] !== '') {
            $o = $o->where('weight-old_weight', '<=', $param['weight_nd']);
        }
        if (isset($param['auto_update_time_st']) && $param['auto_update_time_st']) {
            $auto_update_time_st = strtotime($param['auto_update_time_st']);
            if ($auto_update_time_st) {
                $o = $o->where('auto_update_time', '>=', $auto_update_time_st);
            }

        }
        if (isset($param['auto_update_time_nd']) && $param['auto_update_time_nd']) {
            $auto_update_time_nd = strtotime($param['auto_update_time_nd'] . " 23:59:59");
            $o = $o->where('auto_update_time', '<=', $auto_update_time_nd);
        }
        $o = $o->where('old_weight!=weight');
        return $o;
    }

    public function diffWeight($page, $page_size, $param)
    {
        $result = ['list' => []];
        $result['page'] = $page;
        $result['page_size'] = $page_size;
        $result['count'] = $this->diffWeightWhere($param)->count();
        if ($result['count'] == 0) {
            return $result;
        }
        $field = 'id,thumb,sku,old_weight,weight,auto_update_time,spu_name,(CAST(weight  AS SIGNED)-CAST(old_weight  AS SIGNED)  ) as diff_weight ';

        $ret = $this->diffWeightWhere($param)->page($page, $page_size)->field($field)->order('diff_weight', 'desc')->select();
        $result['list'] = $this->fillDiffWeightData($ret);
        return $result;
    }

    public function fillDiffWeightData($ret)
    {
        $result = [];
        foreach ($ret as $k => $v) {
            $row = [];
            $row['thumb'] = GoodsImage::getThumbPath($v['thumb'], 0, 0);
            $row['id'] = $v['id'];
            $row['sku'] = $v['sku'];
            $row['sku_alias'] = ServerGoodsSkuAlias::getAliasBySkuId($v['id']);
            $row['weight'] = $v['weight'];
            $row['old_weight'] = $v['old_weight'];
            $row['diff_weight'] = $v['weight'] - $v['old_weight'];
            $row['diff_scale'] = !$v['old_weight'] ? 0 : number_format($row['diff_weight'] / $v['old_weight'], 2, '.', '');
            $row['diff_scale'] = $row['diff_scale'] ? ($row['diff_scale'] * 100) . "%" : "0";
            $row['auto_update_time'] = $v['auto_update_time'] ? date('Y-m-d H:i:s', $v['auto_update_time']) : "--";
            $row['is_auto_update_weight'] = $v['auto_update_time'] ? '关闭' : '开启';
            $row['spu_name'] = $v['spu_name'];
            $result[] = $row;
        }
        return $result;
    }

    public function fillDiffWeightExportData($ret)
    {
        $result = [];
        foreach ($ret as $k => $v) {
            $row = [];
            $row['thumb'] = GoodsImage::getThumbPath($v['thumb'], 0, 0);
            $row['id'] = $v['id'];
            $row['sku'] = $v['sku'];
            $alias = ServerGoodsSkuAlias::getAliasBySkuId($v['id']);
            $row['weight'] = $v['weight'];
            $row['sku_alias'] = implode('/', $alias);
            $row['old_weight'] = $v['old_weight'];
            $row['diff_weight'] = $v['weight'] - $v['old_weight'];
            $row['diff_scale'] = !$v['old_weight'] ? 0 : number_format($row['diff_weight'] / $v['old_weight'], 2, '.', '');
            $row['diff_scale'] = $row['diff_scale'] ? ($row['diff_scale'] * 100) . "%" : "0";
            $row['auto_update_time'] = $v['auto_update_time'] ? date('Y-m-d H:i:s', $v['auto_update_time']) : "--";
            $row['is_auto_update_weight'] = $v['auto_update_time'] ? '关闭' : '开启';
            $row['spu_name'] = $v['spu_name'];
            $result[] = $row;
        }
        return $result;
    }

    public function getDiffWeightExportCount($param)
    {
        return $this->diffWeightWhere($param)->count();
    }

    public function diffWeightExportField()
    {
        $header = [
            ['title' => 'ID', 'key' => 'id', 'width' => 10],
            ['title' => 'sku', 'key' => 'sku', 'width' => 35],
            ['title' => 'sku别名', 'key' => 'sku_alias', 'width' => 15],
            ['title' => '产品名称', 'key' => 'spu_name', 'width' => 25],
            ['title' => '原重量(g)', 'key' => 'old_weight', 'width' => 20],
            ['title' => '现重量(g)', 'key' => 'weight', 'width' => 20],
            ['title' => '差异重量(g)', 'key' => 'diff_weight', 'width' => 25],
            ['title' => '差异比例', 'key' => 'diff_scale', 'width' => 20],
            ['title' => '自动校准', 'key' => 'is_auto_update_weight', 'width' => 40],
            ['title' => '校准时间', 'key' => 'auto_update_time', 'width' => 20]
        ];
        return $header;

    }

    public function diffWeightExport($param = [])
    {
        set_time_limit(0);
        ini_set('memory_limit', '128M');
        $header = $this->diffWeightExportField();
        $userInfo = Common::getUserInfo();
        $downFileName = isset($param['file_name']) ? $param['file_name'] : 'SKU重量差异' . date('Y-m-d_H-i-s');
        $downFileName .= "({$userInfo['realname']}).csv";
        $ids = isset($param['ids']) ? json_decode($param['ids'], true) : [];
        if ($ids) {
            $o = new GoodsSkuModel();
            $result = $o->where('id', 'in', $ids)->select();
            if ($result) {
                $result = $this->fillDiffWeightExportData($result);
                $file = [
                    'name' => 'sku重量差异',
                    'path' => 'goods'
                ];
                $ExcelExport = new DownloadFileService();
                return $ExcelExport->export($result, $header, $file);
            }
            throw new Exception('所选id导出数据为空!');
        }
        if ($param['count'] <= 1000) {
            $ret = $this->diffWeightWhere($param)->select();
            if ($ret) {
                $result = $this->fillDiffWeightExportData($ret);
                $file = [
                    'name' => 'SKU重量差异',
                    'path' => 'goods'
                ];
                $ExcelExport = new DownloadFileService();
                return $ExcelExport->export($result, $header, $file);
            }
            throw new Exception('导出数据为空!');
        }
        $sql = $this->diffWeightWhere($param)->order('id', 'desc')->select(false);
        $page = 1;
        $page_size = 10000;
        $page_total = ceil($param['count'] / $page_size);
        $fileName = str_replace('.csv', '', $downFileName);
        $file = ROOT_PATH . 'public' . DS . 'download' . DS . 'goods';
        $filePath = $file . DS . $downFileName;
        $aHeader = [];
        foreach ($header as $v) {
            $aHeader[] = $v['title'];
        }
        $fp = fopen($filePath, 'w+');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, $aHeader);
        fclose($fp);
        do {
            $offset = ($page - 1) * $page_size;
            $dosql = $sql . " limit  {$offset},{$page_size}";
            $Q = new Query();
            $a = $Q->query($dosql, [], true, true);
            $fp = fopen($filePath, 'a');
            while ($v = $a->fetch(PDO::FETCH_ASSOC)) {
                $row = [];
                $row['thumb'] = GoodsImage::getThumbPath($v['thumb'], 0, 0);
                $row['id'] = $v['id'];
                $row['sku'] = $v['sku'];
                $alias = ServerGoodsSkuAlias::getAliasBySkuId($v['id']);
                $row['sku_alias'] = implode('/', $alias);;
                $row['old_weight'] = $v['old_weight'];
                $row['diff_weight'] = $v['weight'] - $v['old_weight'];
                $row['diff_scale'] = !$v['old_weight'] ? 0 : number_format($row['diff_weight'] / $v['old_weight'], 2, '.', '');
                $row['diff_scale'] = $row['diff_scale'] ? ($row['diff_scale'] * 100) . "%" : "0";
                $row['auto_update_time'] = $v['auto_update_time'] ? date('Y-m-d H:i:s', $v['auto_update_time']) : "--";
                $row['is_auto_update_weight'] = $v['auto_update_time'] ? '关闭' : '关闭';
                $row['spu_name'] = $v['spu_name'];
                $rowContent = [];
                foreach ($header as $h) {
                    $field = $h['key'];
                    $value = isset($row[$field]) ? $row[$field] : '';
                    $rowContent[] = $value;
                }
                fputcsv($fp, $rowContent);
            }
            unset($a);
            unset($Q);
            fclose($fp);
            $page++;
        } while ($page <= $page_total);
        $zipPath = $file . DS . $fileName . ".zip";
        $PHPZip = new PHPZip();
        $zipData = [
            [
                'name' => $fileName,
                'path' => $filePath
            ]
        ];
        $PHPZip->saveZip($zipData, $zipPath);
        @unlink($filePath);
        $applyRecord = ReportExportFiles::get($param['apply_id']);
        $applyRecord['exported_time'] = time();
        $applyRecord['download_url'] = '/download/goods/' . $fileName . ".zip";
        $applyRecord['status'] = 1;
        $applyRecord->isUpdate()->save();
    }

    public function checkStoppedParam($param)
    {
        $data = [];
        $data['channel_ids'] = [];
        !empty($param['channel_ids']) && $data['channel_ids'] = json_decode($param['channel_ids'], true);
        isset($param['skus']) && $data['skus'] = json_decode($param['skus'], true);
        isset($param['content']) && $data['content'] = $param['content'];
        isset($param['extension']) && $data['extension'] = $param['extension'];
        return $data;
    }

    public function stopped($param, $userInfo)
    {
        $data = $this->checkStoppedParam($param);
        if (!empty($param['content'])) {
            return $this->stoppedExcell($data, $userInfo);
        } else {
            return $this->stoppedSku($data, $userInfo);
        }
    }

    const STOPPED_HEADER = [
        'SKU'
    ];

    protected function checkHeader($result)
    {
        if (!$result) {
            throw new Exception("未收到该文件的数据");
        }

        $row = reset($result);
        $aRowFiles = array_keys($row);
        $aDiffRowField = array_diff(self::STOPPED_HEADER, $aRowFiles);
        if (!empty($aDiffRowField)) {
            throw new Exception("缺少列名[" . implode(';', $aDiffRowField) . "]");
        }
    }

    public function stoppedExcell($data, $userInfo)
    {
        $filename = 'upload/' . uniqid() . '.' . $data['extension'];
        GoodsImport::saveFile($filename, $data);
        try {
            $result = Excel::readExcel($filename);
            @unlink($filename);
            $this->checkHeader($result);
            $len = count($result);
            if ($len > 20000) {
                throw new Exception('当前数据超越2W条，请分批处理');
            }
            $queue = new UniqueQueuer(GoodsSkuBatchStoppedQueue::class);
            foreach ($result as $v) {
                $data = [
                    'sku' => $v['SKU'],
                    'user_id' => $userInfo['user_id'],
                    'channel_ids' => $data['channel_ids']
                ];
                $queue->push($data);
            }
            return ['已将这' . $len . "条记录推至后台处理"];
        } catch (Exception $ex) {
            @unlink($filename);
            throw new Exception($ex->getMessage());
        }
    }

    public function stoppedSku($data, $userInfo)
    {
        if (empty($data['skus'])) {
            throw new Exception('skus不能为空');
        }
        if (count($data['skus']) >= 200) {
            throw new Exception('处理数据过多，请使用excell导入');
        }
        $responseData = [];
        foreach ($data['skus'] as $sku) {
            try {
                $this->stoppedSkuId($sku, $userInfo['user_id'], $data['channel_ids']);
                $responseData[] = ['error' => 0, 'message' => $sku . "下架成功"];
            } catch (Exception $ex) {
                $responseData[] = ['error' => 1, 'message' => $sku . $ex->getMessage()];
            }
        }
        return ['message' => '执行成功', 'data' => $responseData];
    }

    public function stoppedSkuId($sku, $user_id, $channel_ids = [])
    {
        $skuId = $this->getSkuIdBySku($sku);
        if (empty($skuId)) {
            throw new Exception('无法识别');
        }
        $goodsHelp = new GoodsHelp();
        $goodsHelp->changeSkuStatus($skuId, 2, $user_id);
        if ($channel_ids) {
            $goodsTort = new GoodsTort();
            $goodsTort->saveSku($skuId, $channel_ids, $user_id);
        }
        $queue = new UniqueQueuer(SkuStopSaleUnbindCargoQueue::class);
        $queue->push(['sku_id' => $skuId]);
    }

    public function stoppedChannel()
    {
        return [
            ['value' => 1, 'label' => 'ebay'],
            ['value' => 2, 'label' => 'amazon'],
            ['value' => 3, 'label' => 'wish'],
            ['value' => 4, 'label' => 'aliExpress'],
        ];
    }

}