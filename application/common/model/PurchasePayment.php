<?php
namespace app\common\model;

use think\Model;

/**
 * Created by PhpStorm.
 * User: Reece
 * Date: 2018/11/06
 * Time: 16:55
 */
class PurchasePayment extends Model
{
    protected $autoWriteTimestamp = true;
    public const STATUS_TEXT = [
        0 => '待采购审核',
        1 => '采购审核不通过',
        2 => '待财务审核',//
        3 => '财务审核不通过',
        4 => '待采购排款',//待财务复核
        5 => '待付款',
        6 => '已付款',
        7 => '取消付款',
        8 => '部分付款',
        -1 => '作废',
        -4 => '财务复核不通过'
    ];

    public function detail()
    {
        return parent::hasMany('FinancePurchase', 'purchase_payment_id', 'id');
    }

    public function log()
    {
        return parent::hasMany('PurchasePaymentLog', 'purchase_payment_id', 'id');
    }

    public function getExportFuYouHeader()
    {
        return [
            'company_name' => ['title' => '供应商名称', 'key' => 'company_name', 'width' => 30, 'need_merge' => 1],
            'purchase_order_id' =>   ['title' => '订单编号', 'key' => 'purchase_order_id', 'width' => 20, 'need_merge' => 1],
            'purchase_time' =>  ['title' => '交易日期', 'key' => 'purchase_time', 'width' => 20, 'need_merge' => 1],
            'private_accounts_name' =>         ['title' => '收款人名称', 'key' => 'private_accounts_name', 'width' => 20, 'need_merge' => 1],
            'opening_id_card' =>     ['title' => '收款人证件号', 'key' => 'opening_id_card', 'width' => 20, 'need_merge' => 1],
            'currency_code' =>        ['title' => '交易币种', 'key' => 'currency_code', 'width' => 20, 'need_merge' => 1],
            'amount' =>              ['title' => '交易金额', 'key' => 'amount', 'width' => 20, 'need_merge' => 1],
            'payment_address' =>              ['title' => '付款人常驻国家/地区', 'key' => 'payment_address', 'width' => 20, 'need_merge' => 1],
            'payment_name' =>              ['title' => '付款人名称', 'key' => 'payment_name', 'width' => 20, 'need_merge' => 1],
            'payment_account' =>            ['title' => '付款账号', 'key' => 'payment_account', 'width' => 20, 'need_merge' => 1],
            'trade_type' =>         ['title' => '贸易类型', 'key' => 'trade_type', 'width' => 20, 'need_merge' => 1],
            'sku_name' =>       ['title' => '商品名称', 'key' => 'sku_name', 'width' => 20, 'need_merge' => 1],
            'accepted_goods_qty' =>            ['title' => '数量', 'key' => 'accepted_goods_qty', 'width' => 20, 'need_merge' => 1],
            'single_price' =>           ['title' => '单价', 'key' => 'single_price', 'width' => 20, 'need_merge' => 1],
        ];
    }

    public function buildExportSQLFields(array $fieldKey): array
    {
        $tableFieldArr = [
            'purchase_payment' => [
                'create_time_date' => 'create_time',
                'supplier' => 'supplier_id',
                'currency_code' => 'currency_code',
                'balance_text' => 'balance_type',
                'payment_status_text' => 'status',
                'payment_time' => 'payment_time',
                'payment_last_time' => 'payment_last_time',
                'total_payment_amount' => 'payment_amount',
                'total_apply_amount' => 'apply_amount',
            ],
            'detail' => [
                'apply_amount' => 'apply_amount',
                'payment_amount' => 'payment_amount',
                'purchase_type_text' => 'purchase_type',
                'purchaser' => 'purchase_user_id',
            ],
            'purchaseOrder' => [
                'purchase_order_code' => 'purchase_order_code',
                'purchase_order_status_text' => 'status',
                'external_number' => 'external_number',
                'purchase_time' => 'create_time',
                '1688_account' => 'external_number',
            ],
        ];
        $exportSqlArr = [
            'purchase_payment' => ['id']
        ];
        if(array_intersect($fieldKey, ['accounts_name', 'accounts_bank', 'accounts', 'swift_address', 'transaction_type'])){
            $exportSqlArr['purchase_payment'][] = 'supplier_id';
        }
        foreach ($fieldKey as $item) {
            foreach ($tableFieldArr as $table => $value) {
                if (in_array($item, array_keys($tableFieldArr[$table]))) {
                    $exportSqlArr[$table][] = $tableFieldArr[$table][$item];
                }
            }
        }
        if (isset($exportSqlArr['detail']) && !isset($exportSqlArr['purchaseOrder'])) {
            $exportSqlArr['detail'][] = 'purchase_payment_id';
        }
        if (isset($exportSqlArr['purchaseOrder'])) {
            $exportSqlArr['detail'][] = 'purchase_payment_id, purchase_order_id';
            $exportSqlArr['purchaseOrder'][] = 'id';
        }
        return $exportSqlArr;
    }
}