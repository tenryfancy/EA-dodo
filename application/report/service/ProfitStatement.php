<?php
// +----------------------------------------------------------------------
// |
// +----------------------------------------------------------------------
// | File  : ProfitStatement.php
// +----------------------------------------------------------------------
// | Author: LiuLianSen <3024046831@qq.com>
// +----------------------------------------------------------------------
// | Date  : 2017-08-04
// +----------------------------------------------------------------------
// +----------------------------------------------------------------------
namespace app\report\service;

use app\common\cache\Cache;
use app\common\model\aliexpress\AliexpressAccount;
use app\common\model\amazon\AmazonAccount;
use app\common\model\cd\CdAccount;
use app\common\model\ebay\EbayAccount;
use app\common\model\joom\JoomShop;
use app\common\model\jumia\JumiaAccount;
use app\common\model\lazada\LazadaAccount;
use app\common\model\newegg\NeweggAccount;
use app\common\model\oberlo\OberloAccount;
use app\common\model\Order;
use app\common\model\pandao\PandaoAccount;
use app\common\model\paytm\PaytmAccount;
use app\common\model\shopee\ShopeeAccount;
use app\common\model\umka\UmkaAccount;
use app\common\model\vova\VovaAccount;
use app\common\model\walmart\WalmartAccount;
use app\common\model\yandex\YandexAccount;
use app\common\model\wish\WishAccount;
use app\common\model\zoodmall\ZoodmallAccount;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\common\service\CommonQueuer;
use app\index\service\Currency;
use app\report\model\ReportExportFiles;
use app\report\queue\ProfitExportQueue;
use erp\AbsServer;
use think\Db;
use think\Exception;
use think\Validate;
use think\Loader;
use app\common\model\OrderDetail as OrderDetailModel;
use app\common\model\WarehouseGoods as WarehouseGoodsModel;
use app\common\model\User as UserModel;
use app\common\model\Department as DepartmentModel;
use app\index\service\DepartmentUserMapService as DepartmentUserMapService;
use app\index\service\ChannelAccount;
use app\common\traits\Export;


Loader::import('phpExcel.PHPExcel', VENDOR_PATH);


class ProfitStatement extends  AbsServer
{

    use Export;

    protected $PCardRate = [
        'yandex' => 0.006,
        'zoodmall' => 0.006,
        'oberlo' => 0.006,
        'amazon' => 0.006,
        'joom' => 0.006,
        'cd' => 0.006,
        'newegg' => 0.006,
        'lazada' => 0.006,
        'shopee' => 0.006,
        'paytm' => 0.006,
        'pandao' => 0.006,
        'walmart' => 0.006,
        'jumia' => 0.006,
        'vova' => 0.006,
        'umka' => 0.006,
        'wish'   => 0.005,
    ];


    protected $colMap = [
        'amazon' => [
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'订单类型','width'=>15],
                'C' => ['title'=>'账号简称', 'width'=>10],
                'D' => ['title'=>'站点',     'width'=>10],
                'E' => ['title'=>'销售员',   'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>10],
                'G' => ['title'=>'销售主管', 'width'=>10],
                'H' => ['title'=>'包裹数', 'width'=>10],
                'I' => ['title'=>'平台订单号', 'width'=>30],
                'J' => ['title'=>'付款日期', 'width'=>15],
                'K' => ['title'=>'发货日期', 'width'=>15],
                'L' => ['title'=>'仓库类型',  'width'=>15],
                'M' => ['title'=>'发货仓库', 'width'=>20],
                'N' => ['title'=>'邮寄方式', 'width'=>20],
                'O' => ['title'=>'包裹号',   'width'=>20],
                'P' => ['title'=>'跟踪号',   'width'=>20],
                'Q' => ['title'=>'物流商单号',   'width'=>20],
                'R' => ['title'=>'总售价原币', 'width'=>10],
                'S' => ['title'=>'渠道成交费原币','width'=>10],
                'T' => ['title'=>'币种',     'width'=>10],
                'U' => ['title'=>'汇率',     'width'=>10],
                'V' => ['title'=>'售价CNY',  'width'=>15],
                'W' => ['title'=>'渠道成交费（CNY）','width'=>20],
                'X' => ['title'=>'收款费用',  'width'=>15],
                'Y' => ['title'=>'商品成本', 'width'=>15],
                'Z' => ['title'=>'包装费用', 'width'=>15],
                'AA' => ['title'=>'物流费用', 'width'=>15],
                'AB' => ['title'=>'头程费用', 'width'=>15],
                'AC' => ['title'=>'利润',     'width'=>10],
                'AD' => ['title'=>'货品总数','width'=>15],
                'AE' => ['title'=>'订单备注','width'=>30],
            ],
            'data' => [
                'order_number'         => ['col'=>'A', 'type' => 'str'],
                'order_type'        => ['col'=>'B','type' => 'str'],
                'account_code'         => ['col'=>'C', 'type' => 'str'],
                'site_code'            => ['col'=>'D', 'type' => 'str'],
                'seller_name'          => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'     => ['col'=>'F', 'type' => 'str'],
                'supervisor_name'      => ['col'=>'G', 'type' => 'str'],
                'order_package_num'    => ['col'=>'H', 'type' => 'str'],
                'channel_order_number' => ['col'=>'I', 'type' => 'str'],
                'pay_time'             => ['col'=>'J', 'type' => 'time_stamp'],
                'shipping_time'        => ['col'=>'K', 'type' => 'str'],
                'warehouse_type'       => ['col'=>'L','type' => 'str'],
                'warehouse_name'       => ['col'=>'M','type' => 'str'],
                'shipping_name'        => ['col'=>'N','type' => 'str'],
                'package_number'       => ['col'=>'O','type' => 'str'],
                'shipping_number'      => ['col'=>'P','type' => 'str'],
                'process_code'         => ['col'=>'Q','type' => 'str'],
                'order_amount'         => ['col'=>'R','type' => 'price'],
                'channel_cost'         => ['col'=>'S','type' => 'str'],
                'currency_code'        => ['col'=>'T','type' => 'str'],
                'rate'                 => ['col'=>'U','type' => 'str'],
                'order_amount_CNY'     => ['col'=>'V','type' => 'price'],
                'channel_cost_CNY'     => ['col'=>'W','type' => 'str'],
                'p_card_cost'          => ['col'=>'X','type' => 'str'],
                'goods_cost'           => ['col'=>'Y','type' => 'str'],
                'package_fee'          => ['col'=>'Z','type' => 'str'],
                'shipping_fee'         => ['col'=>'AA','type' => 'str'],
                'first_fee'            => ['col'=>'AB','type' => 'str'],
                'profit'               => ['col'=>'AC','type' => 'str'],
                'sku_count'        => ['col'=>'AD','type' => 'str'],
                'order_note'        => ['col'=>'AE','type' => 'str'],
            ]
        ],
        'wish' => [
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'订单类型','width'=>15],
                'C' => ['title'=>'平台订单号',   'width'=>30],
                'D' => ['title'=>'包裹数', 'width'=>10],
                'E' => ['title'=>'账号简称', 'width'=>10],
                'F' => ['title'=>'销售员',   'width'=>10],
                'G' => ['title'=>'销售组长', 'width'=>10],
                'H' => ['title'=>'国家',     'width'=>10],
                'I' => ['title'=>'编码',     'width'=>10],
                'J' => ['title'=>'付款日期', 'width'=>15],
                'K' => ['title'=>'发货日期', 'width'=>15],
                'L' => ['title'=>'仓库类型',     'width'=>15],
                'M' => ['title'=>'发货仓库', 'width'=>20],
                'N' => ['title'=>'邮寄方式', 'width'=>20],
                'O' => ['title'=>'包裹号',   'width'=>20],
                'P' => ['title'=>'跟踪号',   'width'=>20],
                'Q' => ['title'=>'物流商单号',   'width'=>20],
                'R' => ['title'=>'总售价CNY',  'width'=>15],
                'S' => ['title'=>'渠道成交CNY','width'=>20],
                'T' => ['title'=>'收款费用',  'width'=>15],
                'U' => ['title'=>'商品成本', 'width'=>15],
                'V' => ['title'=>'包装费用', 'width'=>15],
                'W' => ['title'=>'物流费用', 'width'=>15],
                'X' => ['title'=>'头程费用', 'width'=>15],
                'Y' => ['title'=>'利润',     'width'=>10],
                'Z' => ['title'=>'货品总数','width'=>15],
                'AA' => ['title'=>'订单备注','width'=>30],
            ],
            'data' => [
                'order_number'         => ['col'=>'A', 'type' => 'str'],
                'order_type'        => ['col'=>'B','type' => 'str'],
                'channel_order_number' => ['col'=>'C', 'type' => 'str'],
                'order_package_num'    => ['col'=>'D', 'type' => 'str'],
                'account_code'         => ['col'=>'E', 'type' => 'str'],
                'seller_name'          => ['col'=>'F', 'type' => 'str'],
                'team_leader_name'     => ['col'=>'G', 'type' => 'str'],
                'country_code'         => ['col'=>'H', 'type' => 'str'],
                'zipcode'              => ['col'=>'I', 'type' => 'str'],
                'pay_time'             => ['col'=>'J', 'type' => 'time_stamp'],
                'shipping_time'        => ['col'=>'K', 'type' => 'str'],
                'warehouse_type'       => ['col'=>'L','type' => 'str'],
                'warehouse_name'       => ['col'=>'M','type' => 'str'],
                'shipping_name'        => ['col'=>'N','type' => 'str'],
                'package_number'       => ['col'=>'O','type' => 'str'],
                'shipping_number'      => ['col'=>'P','type' => 'str'],
                'process_code'         => ['col'=>'Q','type' => 'str'],
                'order_amount_CNY'     => ['col'=>'R','type' => 'price'],
                'channel_cost_CNY'     => ['col'=>'S','type' => 'str'],
                'p_card_cost'          => ['col'=>'T','type' => 'str'],
                'goods_cost'           => ['col'=>'U','type' => 'str'],
                'package_fee'          => ['col'=>'V','type' => 'str'],
                'shipping_fee'         => ['col'=>'W','type' => 'str'],
                'first_fee'            => ['col'=>'X','type' => 'str'],
                'profit'               => ['col'=>'Y','type' => 'str'],
                'sku_count'            => ['col'=>'Z','type' => 'str'],
                'order_note'        => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'aliExpress' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'订单类型','width'=>15],
                'C' => ['title'=>'平台订单号',   'width'=>30],
                'D' => ['title'=>'包裹数', 'width'=>10],
                'E' => ['title'=>'账号简称', 'width'=>10],
                'F' => ['title'=>'付款日期', 'width'=>15],
                'G' => ['title'=>'发货日期', 'width'=>15],
                'H' => ['title'=>'仓库类型',     'width'=>15],
                'I' => ['title'=>'发货仓库', 'width'=>20],
                'J' => ['title'=>'邮寄方式', 'width'=>20],
                'K' => ['title'=>'包裹号',   'width'=>20],
                'L' => ['title'=>'跟踪号',   'width'=>20],
                'M' => ['title'=>'物流商单号',   'width'=>20],
                'N' => ['title'=>'总售价原币', 'width'=>15],
                'O' => ['title'=>'渠道成交费原币','width'=>20],
                'P' => ['title'=>'币种',     'width'=>10],
                'Q' => ['title'=>'汇率',     'width'=>10],
                'R' => ['title'=>'总售价CNY',  'width'=>15],
                'S' => ['title'=>'渠道成交费CNY','width'=>20],
                'T' => ['title'=>'商品成本', 'width'=>15],
                'U' => ['title'=>'包装费用', 'width'=>15],
                'V' => ['title'=>'物流费用', 'width'=>15],
                'W' => ['title'=>'利润',     'width'=>10],
                'X' => ['title'=>'货品总数','width'=>15],
                'Y' => ['title'=>'订单备注','width'=>30],

            ],
            'data' => [
                'order_number'         => ['col'=>'A', 'type' => 'str'],
                'order_type'        => ['col'=>'B','type' => 'str'],
                'channel_order_number' => ['col'=>'C', 'type' => 'str'],
                'order_package_num'    => ['col'=>'D', 'type' => 'str'],
                'account_code'         => ['col'=>'E', 'type' => 'str'],
                'pay_time'             => ['col'=>'F', 'type' => 'time_stamp'],
                'shipping_time'        => ['col'=>'G', 'type' => 'str'],
                'warehouse_type'       => ['col'=>'H','type' => 'str'],
                'warehouse_name'       => ['col'=>'I','type' => 'str'],
                'shipping_name'        => ['col'=>'J','type' => 'str'],
                'package_number'       => ['col'=>'K','type' => 'str'],
                'shipping_number'      => ['col'=>'L','type' => 'str'],
                'process_code'         => ['col'=>'M','type' => 'str'],
                'order_amount'         => ['col'=>'N','type' => 'price'],
                'channel_cost'         => ['col'=>'O','type' => 'str'],
                'currency_code'        => ['col'=>'P','type' => 'str'],
                'rate'                 => ['col'=>'Q','type' => 'str'],
                'order_amount_CNY'     => ['col'=>'R','type' => 'price'],
                'channel_cost_CNY'     => ['col'=>'S','type' => 'str'],
                'goods_cost'           => ['col'=>'T','type' => 'str'],
                'package_fee'          => ['col'=>'U','type' => 'str'],
                'shipping_fee'         => ['col'=>'V','type' => 'str'],
                'profit'               => ['col'=>'W','type' => 'str'],
                'sku_count'            => ['col'=>'X','type' => 'str'],
                'order_note'        => ['col'=>'Y','type' => 'str'],
            ]
        ],
        'ebay' => [
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'订单类型','width'=>15],
                'C' => ['title'=>'平台订单号',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'包裹数', 'width'=>10],
                'F' => ['title'=>'付款日期', 'width'=>15],
                'G' => ['title'=>'发货日期', 'width'=>15],
                'H' => ['title'=>'仓库类型',     'width'=>15],
                'I' => ['title'=>'发货仓库', 'width'=>20],
                'J' => ['title'=>'邮寄方式', 'width'=>20],
                'K' => ['title'=>'包裹号',   'width'=>20],
                'L' => ['title'=>'跟踪号',   'width'=>20],
                'M' => ['title'=>'物流商单号',   'width'=>20],
                'N' => ['title'=>'币种',     'width'=>10],
                'O' => ['title'=>'汇率',     'width'=>10],
                'P' => ['title'=>'总售价CNY',  'width'=>15],
                'Q' => ['title'=>'渠道成交费CNY',  'width'=>20],
                'R' => ['title'=>'PayPal费CNY','width'=>20],
                'S' => ['title'=>'货币转换费CNY','width'=>20],
                'T' => ['title'=>'商品成本', 'width'=>15],
                'U' => ['title'=>'包装费用', 'width'=>15],
                'V' => ['title'=>'物流费用', 'width'=>15],
                'W' => ['title'=>'头程费用', 'width'=>15],
                'X' => ['title'=>'利润',     'width'=>10],
                'Y' => ['title'=>'货品总数','width'=>15],
                'Z' => ['title'=>'订单备注','width'=>30],
            ],
            'data' => [
                'order_number'         => ['col'=>'A', 'type' => 'str'],
                'order_type'        => ['col'=>'B','type' => 'str'],
                'channel_order_number' => ['col'=>'C', 'type' => 'str'],
                'account_code'         => ['col'=>'D', 'type' => 'str'],
                'order_package_num'    => ['col'=>'E', 'type' => 'str'],
                'pay_time'             => ['col'=>'F', 'type' => 'time_stamp'],
                'shipping_time'        => ['col'=>'G', 'type' => 'str'],
                'warehouse_type'       => ['col'=>'H','type' => 'str'],
                'warehouse_name'       => ['col'=>'I','type' => 'str'],
                'shipping_name'        => ['col'=>'J','type' => 'str'],
                'package_number'       => ['col'=>'K','type' => 'str'],
                'shipping_number'      => ['col'=>'L','type' => 'str'],
                'process_code'         => ['col'=>'M','type' => 'str'],
                'currency_code'        => ['col'=>'N','type' => 'str'],
                'rate'                 => ['col'=>'O','type' => 'str'],
                'order_amount_CNY'     => ['col'=>'P','type' => 'price'],
                'channel_cost_CNY'     => ['col'=>'Q','type' => 'str'],
                'mc_fee_CNY'           => ['col'=>'R','type' => 'str'],
                'conversion_fee_CNY'   => ['col'=>'S','type' => 'str'],
                'goods_cost'           => ['col'=>'T','type' => 'str'],
                'package_fee'          => ['col'=>'U','type' => 'str'],
                'shipping_fee'         => ['col'=>'V','type' => 'str'],
                'first_fee'            => ['col'=>'W','type' => 'str'],
                'profit'               => ['col'=>'X','type' => 'str'],
                'sku_count'        => ['col'=>'Y','type' => 'str'],
                'order_note'        => ['col'=>'Z','type' => 'str'],

            ]
        ],
        'joom' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'店铺简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],
            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],

            ]
        ],
        'lazada' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'shopee' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],
            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'paytm' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],
            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'pandao' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],
            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'walmart' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'jumia' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'美元汇率',  'width'=>20],
                'R' => ['title'=>'售价奈拉','width'=>20],
                'S' => ['title'=>'奈拉汇率','width'=>20],
                'T' => ['title'=>'总售价CNY', 'width'=>15],
                'U' => ['title'=>'商品成本', 'width'=>15],
                'V' => ['title'=>'包装费用', 'width'=>15],
                'W' => ['title'=>'物流费用', 'width'=>15],
                'X' => ['title'=>'头程报关费',     'width'=>10],
                'Y' => ['title'=>'利润','width'=>15],
                'Z' => ['title'=>'估算邮费','width'=>30],
                'AA' => ['title'=>'订单类型','width'=>15],
                'AB' => ['title'=>'订单备注','width'=>30],
                'AC' => ['title'=>'总售价原币', 'width'=>10],
                'AD' => ['title'=>'币种',     'width'=>10],
                'AE' => ['title'=>'汇率',     'width'=>10],
            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount'          => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'           => ['col'=>'P','type' => 'str'],
                'rate_USD'              => ['col'=>'Q','type' => 'str'],
                'selling_price'         => ['col'=>'R','type' => 'str'],
                'rate'                  => ['col'=>'S','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'T','type' => 'price'],
                'goods_cost'            => ['col'=>'U','type' => 'str'],
                'package_fee'           => ['col'=>'V','type' => 'str'],
                'shipping_fee'          => ['col'=>'W','type' => 'str'],
                'first_fee'             => ['col'=>'X','type' => 'str'],
                'profit'                => ['col'=>'Y','type' => 'str'],
                'estimated_fee'         => ['col'=>'Z','type' => 'str'],
                'order_type'            => ['col'=>'AA','type' => 'str'],
                'order_note'            => ['col'=>'AB','type' => 'str'],
                'order_amount'         => ['col'=>'AC','type' => 'price'],
                'currency_code'        => ['col'=>'AD','type' => 'str'],
                'rate'                 => ['col'=>'AE','type' => 'str'],
            ]
        ],
        'vova' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'umka' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'cd' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'newegg' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],
        'oberlo' =>[
            'title' => [
                'A' => ['title'=>'订单号',   'width'=>30],
                'B' => ['title'=>'平台订单号','width'=>15],
                'C' => ['title'=>'包裹数',   'width'=>30],
                'D' => ['title'=>'账号简称', 'width'=>10],
                'E' => ['title'=>'销售员', 'width'=>10],
                'F' => ['title'=>'销售组长', 'width'=>15],
                'G' => ['title'=>'付款日期', 'width'=>15],
                'H' => ['title'=>'发货日期',     'width'=>15],
                'I' => ['title'=>'仓库', 'width'=>20],
                'J' => ['title'=>'发货仓库', 'width'=>20],
                'K' => ['title'=>'运输方式',   'width'=>20],
                'L' => ['title'=>'包裹号',   'width'=>20],
                'M' => ['title'=>'跟踪号',   'width'=>20],
                'N' => ['title'=>'总售价', 'width'=>15],
                'O' => ['title'=>'渠道成交费','width'=>20],
                'P' => ['title'=>'P卡费用',     'width'=>10],
                'Q' => ['title'=>'商品成本',     'width'=>10],
                'R' => ['title'=>'包装费用',  'width'=>15],
                'S' => ['title'=>'物流费用','width'=>20],
                'T' => ['title'=>'头程报关费', 'width'=>15],
                'U' => ['title'=>'利润', 'width'=>15],
                'V' => ['title'=>'估算邮费', 'width'=>15],
                'W' => ['title'=>'订单类型','width'=>15],
                'X' => ['title'=>'订单备注','width'=>30],
                'Y' => ['title'=>'总售价原币', 'width'=>10],
                'Z' => ['title'=>'币种',     'width'=>10],
                'AA' => ['title'=>'汇率',     'width'=>10],

            ],
            'data' => [
                'order_number'          => ['col'=>'A', 'type' => 'str'],
                'channel_order_number'  => ['col'=>'B','type' => 'str'],
                'order_package_num'     => ['col'=>'C', 'type' => 'str'],
                'account_code'          => ['col'=>'D', 'type' => 'str'],
                'seller_name'           => ['col'=>'E', 'type' => 'str'],
                'team_leader_name'      => ['col'=>'F', 'type' => 'str'],
                'pay_time'              => ['col'=>'G', 'type' => 'time_stamp'],
                'shipping_time'         => ['col'=>'H','type' => 'str'],
                'warehouse_type'        => ['col'=>'I','type' => 'str'],
                'warehouse_name'        => ['col'=>'J','type' => 'str'],
                'shipping_name'         => ['col'=>'K','type' => 'str'],
                'package_number'        => ['col'=>'L','type' => 'str'],
                'shipping_number'       => ['col'=>'M','type' => 'str'],
                'order_amount_CNY'      => ['col'=>'N','type' => 'price'],
                'channel_cost_CNY'      => ['col'=>'O','type' => 'str'],
                'p_card_cost'                => ['col'=>'P','type' => 'str'],
                'goods_cost'            => ['col'=>'Q','type' => 'str'],
                'package_fee'           => ['col'=>'R','type' => 'str'],
                'shipping_fee'          => ['col'=>'S','type' => 'str'],
                'first_fee'             => ['col'=>'T','type' => 'str'],
                'profit'                => ['col'=>'U','type' => 'str'],
                'estimated_fee'         => ['col'=>'V','type' => 'str'],
                'order_type'            => ['col'=>'W','type' => 'str'],
                'order_note'            => ['col'=>'X','type' => 'str'],
                'order_amount'         => ['col'=>'Y','type' => 'price'],
                'currency_code'        => ['col'=>'Z','type' => 'str'],
                'rate'                 => ['col'=>'AA','type' => 'str'],
            ]
        ],

    ];

    private function initFieldValue($channel_id, $data)
    {
        $dataMap = [];
        switch ($channel_id){
            case 1:
                $dataMap  = $this->colMap['ebay']['data'];
                break;
            case 2:
                $dataMap  = $this->colMap['amazon']['data'];
                break;
            case 3:
                $dataMap  = $this->colMap['wish']['data'];
                break;
            case 4:
                $dataMap  = $this->colMap['aliExpress']['data'];
                break;
            case ChannelAccountConst::channel_Joom:
                $dataMap  = $this->colMap['joom']['data'];
                break;
            case ChannelAccountConst::channel_Shopee:
                $dataMap  = $this->colMap['shopee']['data'];
                break;
            case ChannelAccountConst::channel_Lazada:
                $dataMap  = $this->colMap['lazada']['data'];
                break;
            case ChannelAccountConst::channel_Paytm:
                $dataMap  = $this->colMap['paytm']['data'];
                break;
            case ChannelAccountConst::channel_Pandao:
                $dataMap  = $this->colMap['pandao']['data'];
                break;
            case ChannelAccountConst::channel_Walmart:
                $dataMap  = $this->colMap['walmart']['data'];
                break;
            case ChannelAccountConst::Channel_Jumia:
                $dataMap  = $this->colMap['jumia']['data'];
                break;
            case ChannelAccountConst::Channel_umka:
                $dataMap  = $this->colMap['umka']['data'];
                break;
            case ChannelAccountConst::channel_Vova:
                $dataMap  = $this->colMap['vova']['data'];
                break;
            case ChannelAccountConst::channel_CD:
                $dataMap  = $this->colMap['cd']['data'];
                break;
            case ChannelAccountConst::channel_Newegg:
                $dataMap  = $this->colMap['newegg']['data'];
                break;
            case ChannelAccountConst::channel_Oberlo:
                $dataMap  = $this->colMap['oberlo']['data'];
                break;
            case ChannelAccountConst::channel_Zoodmall:
                $dataMap  = $this->colMap['oberlo']['data'];
                break;
            case ChannelAccountConst::channel_Yandex:
                $dataMap  = $this->colMap['oberlo']['data'];
                break;

        }
        $result = [];
        $is_data = 1;
        foreach($dataMap as $key=>$value){
            if($key=='shipping_number'){
                $is_data = 0;
            }
            if($is_data) {;
                $result[$key] = isset($data[$key]) ? $data[$key] : '';
            } else {
                $result[$key] ='-';
            }
        }
        return $result;
    }


    /**
     * 获取参数
     * @param array $params
     * @param $key
     * @param $default
     * @return mixed
     */
    public function getParameter(array $params , $key,$default)
    {
        $v = $default;
        if(isset($params[$key]) && $params[$key]){
            $v = $params[$key];
        }
        return $v;
    }

    /**
     * 查询平台利润报表
     * @return array
     */
    public function search($params)
    {
        $page  = $this->getParameter($params,'page',1);
        $pageSize  = $this->getParameter($params,'pageSize',10);
        $timeField = $this->getParameter($params,'time_field','');
        $timeSort  = $this->getParameter($params,'time_sort','ASC');
        if(!in_array($timeSort,['ASC','DESC'])) $timeSort = 'ASC';
        $count   = $this->searchCount($params);
        $data = $this->assemblyData($this->doSearch($params,$page,$pageSize,$timeField,$timeSort));
        return ['page' => $page,'pageSize'=>$pageSize,'count'=>$count,'data'=>$data];
    }


    /**
     * 创建导出文件名
     * @param $channelId
     * @return string
     * @throws Exception
     */
    protected function createExportFileName($channelId,$userId,$params)
    {
        $channelAccountService = new ChannelAccount();
        $fileName = '';
        switch ($channelId){
            case 1:
                $fileName = 'ebay平台利润报表';
                break;
            case 2:
                $fileName = '亚马逊平台利润报表';
                break;
            case 3:
                $fileName = 'WISH平台利润报表';
                break;
            case 4:
                $fileName = '速卖通平台利润报表';
                break;
            case ChannelAccountConst::channel_Joom:
                $fileName = 'Joom平台利润报表';
                break;
            case ChannelAccountConst::channel_Shopee:
                $fileName = 'Shopee平台利润报表';
                break;
            case ChannelAccountConst::channel_Lazada:
                $fileName = 'Lazada平台利润报表';
                break;
            case ChannelAccountConst::channel_Paytm:
                $fileName = 'Paytm平台利润报表';
                break;
            case ChannelAccountConst::channel_Pandao:
                $fileName = 'Pandao平台利润报表';
                break;
            case ChannelAccountConst::channel_Walmart:
                $fileName = 'Walmart平台利润报表';
                break;
            case ChannelAccountConst::Channel_Jumia:
                $fileName = 'Jumia平台利润报表';
                break;
            case ChannelAccountConst::Channel_umka:
                $fileName = 'Umka平台利润报表';
                break;
            case ChannelAccountConst::channel_Vova:
                $fileName = 'Vova平台利润报表';
                break;
            case ChannelAccountConst::channel_CD:
                $fileName = 'CD平台利润报表';
                break;
            case ChannelAccountConst::channel_Newegg:
                $fileName = 'Newegg平台利润报表';
                break;
            case ChannelAccountConst::channel_Oberlo:
                $fileName = 'Oberlo平台利润报表';
                break;
            case ChannelAccountConst::channel_Zoodmall:
                $fileName = 'Zoodmall平台利润报表';
                break;
            case ChannelAccountConst::channel_Yandex:
                $fileName = 'Yandex平台利润报表';
                break;
            default:throw new Exception('不支持的平台');
        }
        $lastID  = (new ReportExportFiles())->order('id desc')->value('id');
        $fileName .= ($lastID+1);
        if (isset($params['site_code']) && $params['site_code']) {
            $fileName .= '_' . $params['site_code'];
        }
        if(isset($params['channel_id']) && isset($params['account_id']) && $params['account_id'] && $params['channel_id']){
            $account = $channelAccountService->getAccount($params['channel_id'] , $params['account_id']);
            $accountCode = param($account, 'code');
            $fileName .= '_'.$accountCode;
        }
        if (isset($params['warehouse_id']) && $params['warehouse_id']) {
            $warehouse_name = Cache::store('warehouse')->getWarehouseNameById($params['warehouse_id']);
            $fileName .= '_'.$warehouse_name;
        }
        $fileName .= '_'.$params['time_start'].'_'.$params['time_end'].'.xlsx';
        return $fileName;
    }


    /**
     * 申请导出
     * @param $params
     * @return bool
     * @throws Exception
     * @throws \Exception
     */
    public function applyExport($params)
    {
        Db::startTrans();
        try{
            $userId = Common::getUserInfo()->toArray()['user_id'];
            $cacher = Cache::handler();
            $lastApplyTime = $cacher->hget('hash:export_apply',$userId);
            if($lastApplyTime && time() - $lastApplyTime < 5){
                throw new Exception('请求过于频繁',400);
            }else{
                $cacher->hset('hash:export_apply',$userId,time());
            }
            if(!isset($params['channel_id']) || trim($params['channel_id']) == ''){
                throw new Exception('平台未设置',400);
            }
            $model = new ReportExportFiles();
            $model->applicant_id     = $userId;
            $model->apply_time       = time();
            $model->export_file_name = $this->createExportFileName($params['channel_id'],$model->applicant_id,$params);
            $model->status =0;
            if(!$model->save()){
                throw new Exception('导出请求创建失败',500);
            }
            $params['file_name'] = $model->export_file_name;
            $params['apply_id'] = $model->id;
            $this->export($params);
            $queuer = new CommonQueuer(ProfitExportQueue::class);
            $queuer->push($params);
            Db::commit();
            return true;
        }catch (\Exception $ex){
            Db::rollback();
            if($ex->getCode()){
                throw $ex;
            }else{
                Cache::handler()->hset(
                    'hash:report_export_apply',
                    $params['apply_id'].'_'.time(),
                    $ex->getMessage());
                throw new Exception('导出请求创建失败',500);
            }
        }
    }

    /**
     * 导出数据至excel文件
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function export($params)
    {
        set_time_limit(0);
        try{
            //ini_set('memory_limit','4096M');
            $applyId =  $this->getParameter($params,'apply_id','');
            if(!$applyId){
                throw new Exception('导出申请id获取失败');
            }
            $fileName = $this->getParameter($params,'file_name','');
            if(!$fileName){
                throw new Exception('导出文件名未设置');
            }
            $fileName = $this->getParameter($params,'file_name','');
            if(!$fileName){
                throw new Exception('导出文件名未设置');
            }

            $downLoadDir = '/download/platform_profit/';
            $saveDir = ROOT_PATH.'public'.$downLoadDir;
            if(!is_dir($saveDir) && !mkdir($saveDir,0777,true)){
                throw new Exception('导出目录创建失败');
            }
            $fullName = $saveDir.$fileName;
            //创建excel对象
            $writer = new \XLSXWriter();
//            $exceler = new \PHPExcel();
//            $exceler->setActiveSheetIndex(0);
//            $sheet = $exceler->getActiveSheet();
//            $titleRowIndex = 1;
//            $dataRowStartIndex = 2;
            $titleMap  = [];
            $lastCol   = 'AB';
            $dataMap   = [];
            switch ($params['channel_id']){
                case 1:
                    $titleMap = $this->colMap['ebay']['title'];
                    $dataMap  = $this->colMap['ebay']['data'];
                    $lastCol  = 'Z';
                    break;
                case 2:
                    $titleMap = $this->colMap['amazon']['title'];
                    $dataMap  = $this->colMap['amazon']['data'];
                    $lastCol = 'AE';
                    break;
                case 3:
                    $titleMap = $this->colMap['wish']['title'];
                    $dataMap  = $this->colMap['wish']['data'];
                    $lastCol  = 'AA';
                    break;
                case 4:
                    $titleMap = $this->colMap['aliExpress']['title'];
                    $dataMap  = $this->colMap['aliExpress']['data'];
                    $lastCol  = 'Y';
                    break;
                case ChannelAccountConst::channel_Joom:
                    $titleMap = $this->colMap['joom']['title'];
                    $dataMap  = $this->colMap['joom']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Shopee:
                    $titleMap = $this->colMap['shopee']['title'];
                    $dataMap  = $this->colMap['shopee']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Lazada:
                    $titleMap = $this->colMap['lazada']['title'];
                    $dataMap  = $this->colMap['lazada']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Paytm:
                    $titleMap = $this->colMap['paytm']['title'];
                    $dataMap  = $this->colMap['paytm']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Pandao:
                    $titleMap = $this->colMap['pandao']['title'];
                    $dataMap  = $this->colMap['pandao']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Walmart:
                    $titleMap = $this->colMap['walmart']['title'];
                    $dataMap  = $this->colMap['walmart']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::Channel_Jumia:
                    $titleMap = $this->colMap['jumia']['title'];
                    $dataMap  = $this->colMap['jumia']['data'];
                    $lastCol  = 'AE';
                    break;
                case ChannelAccountConst::channel_Vova:
                    $titleMap = $this->colMap['vova']['title'];
                    $dataMap  = $this->colMap['vova']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::Channel_umka:
                    $titleMap = $this->colMap['umka']['title'];
                    $dataMap  = $this->colMap['umka']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_CD:
                    $titleMap = $this->colMap['cd']['title'];
                    $dataMap  = $this->colMap['cd']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Newegg:
                    $titleMap = $this->colMap['newegg']['title'];
                    $dataMap  = $this->colMap['newegg']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Oberlo:
                    $titleMap = $this->colMap['oberlo']['title'];
                    $dataMap  = $this->colMap['oberlo']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Zoodmall:
                    $titleMap = $this->colMap['oberlo']['title'];
                    $dataMap  = $this->colMap['oberlo']['data'];
                    $lastCol  = 'AA';
                    break;
                case ChannelAccountConst::channel_Yandex:
                    $titleMap = $this->colMap['oberlo']['title'];
                    $dataMap  = $this->colMap['oberlo']['data'];
                    $lastCol  = 'AA';
                    break;
            }
            $title = [];
            foreach ($dataMap as $k => $v) {
                array_push($title, $k);
                $titleMap[$v['col']]['type'] = $v['type'];
            }
            $titleOrderData = [];
            foreach ($titleMap as $t => $tt){
                if(isset($tt['type']) && $tt['type'] =='price'){
                    $titleOrderData[$tt['title']] = 'price';
                }else{
                    $titleOrderData[$tt['title']] = 'string';
                }

            }

            //设置表头和表头样式
//            foreach ($titleMap as $col => $set) {
//                $sheet->getColumnDimension($col)->setWidth($set['width']);
//                $sheet->getCell($col . $titleRowIndex)->setValue($set['title']);
//                $sheet->getStyle($col . $titleRowIndex)
//                    ->getFill()
//                    ->setFillType(\PHPExcel_Style_Fill::FILL_SOLID)
//                    ->getStartColor()->setRGB('E8811C');
//                $sheet->getStyle($col . $titleRowIndex)
//                    ->getBorders()
//                    ->getAllBorders()
//                    ->setBorderStyle(\PHPExcel_Style_Border::BORDER_THIN);
//            }
//            $sheet->setAutoFilter('A1:'.$lastCol.'1');

            //统计需要导出的数据行
            $count    = $this->searchCount($params);
            $pageSize = 1;
            $timeField = $this->getParameter($params,'time_field','');
            $timeSort  = $this->getParameter($params,'time_sort','ASC');
            if(!in_array($timeSort,['ASC','DESC'])) $timeSort = 'ASC';
            $loop     = ceil($count/$pageSize);
            $writer->writeSheetHeader('Sheet1', $titleOrderData);
            //分批导出
            for($i = 0;$i<$loop;$i++){
                $data = $this->assemblyData($this->doSearch($params,$i+1,$pageSize,$timeField,$timeSort), 2, $title);
                foreach ($data as $r){
//                    foreach ($dataMap as $field => $set){
//                        $cell = $sheet->getCell($set['col']. $dataRowStartIndex);
//                        switch ($set['type']){
//                            case 'time_stamp':
//                                if(empty($r[$field])){
//                                    $cell->setValue('');
//                                }else{
//                                    $cell->setValue(date('Y-m-d',$r[$field]));
//                                }
//                                break;
//                            case 'numeric':
//                                $cell->setDataType(\PHPExcel_Cell_DataType::TYPE_NUMERIC);
//                                if(empty($r[$field])){
//                                    $cell->setValue(0);
//                                }else{
//                                    $cell->setValue($r[$field]);
//                                }
//                                break;
//                            default:
//                                $cell->setDataType(\PHPExcel_Cell_DataType::TYPE_STRING);
//                                if(is_null($r[$field])) {
//                                    $r[$field] = '';
//                                }
//                                $cell->setValueExplicit($r[$field], \PHPExcel_Cell_DataType::TYPE_STRING);
//                        }
//                    }
//                    $dataRowStartIndex++;
                    $writer->writeSheetRow('Sheet1', $r);
                    break;
                }
                unset($data);
            }
            $writer->writeToFile($fullName);
            var_dump( $downLoadDir.$fileName);exit;
//            $writer = \PHPExcel_IOFactory::createWriter($exceler,'Excel2007');
//            $writer->save($fullName);
            if(is_file($fullName)){
                $applyRecord = ReportExportFiles::get($applyId);
                $applyRecord->exported_time = time();
                $applyRecord->download_url = $downLoadDir.$fileName;
                $applyRecord->status = 1;
                $applyRecord->isUpdate()->save();
            }else{
                throw new Exception('文件写入失败');
            }
        }catch (\Exception $ex){
            $applyRecord = ReportExportFiles::get($applyId);
            $applyRecord->status = 2;
            $applyRecord->error_message = $ex->getMessage();
            $applyRecord->isUpdate()->save();
            Cache::handler()->hset(
                'hash:report_export',
                $applyId.'_'.time(),
                '申请id: '.$applyId.',导出失败:'.$ex->getMessage());

        }
        return true;
    }


    /**
     * 计算获取平台订单的利润
     * @param $channelId
     * @param $recordData
     * @return int
     */
    protected function getPlatformOrderProfit($channelId,$recordData)
    {
        $profit = 0;
        switch ($channelId){
            case 2: //亚马逊
            case 3: //wish
                $profit =  $recordData['order_amount_CNY']
                            -  $recordData['channel_cost_CNY']
                            -  $recordData['p_card_cost']
                            -  $recordData['goods_cost']
                            -  $recordData['package_fee']
                            -  $recordData['shipping_fee']
                            -  $recordData['first_fee'];
                break;
            case 4: //速卖通
                $profit =  $recordData['order_amount_CNY']
                            -  $recordData['channel_cost_CNY']
                            -  $recordData['goods_cost']
                            -  $recordData['package_fee']
                            -  $recordData['shipping_fee']
                            -  $recordData['first_fee'];
                break;
            case 1: //ebay
                $profit =  $recordData['order_amount_CNY']
                    -  $recordData['channel_cost_CNY']
                    -  $recordData['mc_fee_CNY']
                    -  $recordData['conversion_fee_CNY']
                    -  $recordData['goods_cost']
                    -  $recordData['package_fee']
                    -  $recordData['shipping_fee']
                    -  $recordData['first_fee'];

                break;
            case ChannelAccountConst::channel_Joom: //joom 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Shopee: //Shopee 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Lazada: //Lazada 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Paytm: //paytm 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Pandao: //pandao 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Walmart: //walmart 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::Channel_Jumia: //jumia 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::Channel_umka: //umka 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Vova: //voma 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_CD: //cd 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Newegg: //newegg 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Oberlo: //oberlo 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Zoodmall: //zoodmall 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
            case ChannelAccountConst::channel_Yandex: //yandex 利润=总售价-渠道成交费-P卡费用-商品成本-包装费用-物流费用-头程报关费
                $profit =  $recordData['order_amount_CNY'] //总售价
                    -  $recordData['channel_cost_CNY']  //渠道成交费
                    -  $recordData['p_card_cost'] //P卡费用
                    -  $recordData['goods_cost'] //商品成本
                    -  $recordData['package_fee'] //包装费用
                    -  $recordData['shipping_fee'] //物流费用
                    -  $recordData['first_fee'];//头程报关费
                break;
        }
        return $profit;

    }

    /**
     * 获取包裹号
     * @param $number
     * @return string
     */
    public function getPackageNumber($number)
    {
        return trim($number);
    }

    /**
     * 组装查询返回数据
     * @param $records
     * @param $type 1-列表 2-导出
     * @param $title
     * @return array
     */
    protected function assemblyData($records, $type=1, $title = [])
    {

        if(empty($records)){
            return [];
        }
        $orderNumbers = [];
        $data = [];
        $info = [];
        $packageService = new \app\order\service\PackageService();
        $packageModel = new \app\common\model\OrderPackage();
        $shippingMethod = new \app\warehouse\service\ShippingMethod();
        $orderNote = new \app\order\service\OrderNoteService();
        $order = new \app\order\service\OrderService();

        $currency = new Currency();
        $rateUSD = $currency->getCurrency('USD');
        foreach ($records as $key => $record){
            $_data = $record->getData();
            $_data['package_fee'] = 0;//后面算
            $_data['shipping_fee'] = 0;//后面算

            $_data['id'] = strval($_data['id']);
//            $_data['channel_order_number'] = $_data['channel_order_number'];//避免科学计数法
            $_data['team_leader_name'] = '';//销售组长
            $_data['supervisor_name']  = '';//销售主管
            $_data['goods_cost']       = $record['cost'];//商品成本
            $_data['package_goods']    = '';//包裹商品
            $_data['p_card_cost']      = 0;//P卡费用
            $_data['conversion_fee']   = 0;//货币转换费
            $_data['mc_fee']           = $_data['mc_fee']?:0;
            $_data['conversion_fee_CNY']   = 0;
            $_data['mc_fee_CNY']      = 0;
            $_data['shipping_time']  = date('Y-m-d H:i:s', $_data['shipping_time']);
            if (!empty($title)) {
                $_data['pay_time']  = date('Y-m-d H:i:s', $_data['pay_time']);
            }

            $_data['order_note']  = $orderNote->noteInfo($_data['id']); //订单备注

            $_data['order_type'] = '';
            switch ($_data['type']) {
                case 0:
                    $_data['order_type'] = '渠道订单';
                    break;
                case 1:
                    $_data['order_type'] = '手工订单';
                    break;
                case 2:
                    $_data['order_type'] = '刷单订单';
                    break;

            }
            //统计货品总数
            //$_data['sku_count'] = OrderDetailModel::where(['order_id'=>$_data['id']])->value('sum(sku_quantity)');

            switch ($_data['channel_id']) {
                case 1: //ebay
                    $ebayAccount = EbayAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $ebayAccount ? $ebayAccount->code : '';
                    if($_data['currency_code'] != 'USD'){
                        $_data['conversion_fee']   = ($_data['order_amount']-$_data['channel_cost']- $_data['mc_fee'])*0.025;
                        $_data['conversion_fee'] < 0 && $_data['conversion_fee'] = 0;
                    }
                    $_data['mc_fee_CNY'] = $_data['mc_fee'] * $_data['rate'];
                    $_data['conversion_fee_CNY'] = $_data['conversion_fee'] * $_data['rate'];

                    break;
                case 2: //amazon
                    $amazonAccount = AmazonAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $amazonAccount ? $amazonAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                                              * $this->PCardRate['amazon'] * $_data['rate'];
                    /*//日元原币保留整数
                    if($_data['currency_code'] != 'JPY'){
                        $_data['order_amount']   = intval($_data['order_amount']);
                        $_data['channel_cost']  = intval($_data['channel_cost']);
                        $_data['p_card_cost']  = intval($_data['channel_cost']);
                    }*/
                    break;
                case 3: //wish
                    $wishAccount = WishAccount::get($_data['channel_account_id']);
                    $_data['account_code'] = $wishAccount ? $wishAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                                              * $this->PCardRate['wish'] * $_data['rate'];
                    break;
                case 4: //aliExpress
                    $aliexpressAccount = AliexpressAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$aliexpressAccount ? $aliexpressAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['wish'] * $_data['rate'];
                    break;
                case ChannelAccountConst::channel_Joom: //joom
                    $joomAccount = JoomShop::get($_data['channel_account_id']);
                    $_data['account_code'] =$joomAccount ? $joomAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['joom'] * $_data['rate']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Shopee: //shopee
                    $shopeeAccount = ShopeeAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$shopeeAccount ? $shopeeAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['shopee'] * $_data['rate']; //P卡费用
                    $_data['order_amount_CNY'] = $_data['order_amount'] * $_data['rate'];
                    break;
                case ChannelAccountConst::channel_Lazada: //lazada
                    $lazadaAccount = LazadaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$lazadaAccount ? $lazadaAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['lazada'] * $_data['rate']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Paytm: //paytm
                    $paytmAccount = PaytmAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$paytmAccount ? $paytmAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['paytm'] * $_data['rate']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Pandao: //pandao
                    $pandaoAccount = PandaoAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$pandaoAccount ? $pandaoAccount->code : '';
                    if($_data['channel_cost'] <= 0){
                        $_data['channel_cost'] = $_data['order_amount'] * 0.06;
                    }
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['pandao'] * $_data['rate']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Walmart: //walmart
                    $walmartAccount = WalmartAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$walmartAccount ? $walmartAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['walmart']; //P卡费用
                    break;
                case ChannelAccountConst::Channel_Jumia: //jumia
                    $jumiaAccount = JumiaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$jumiaAccount ? $jumiaAccount->code : '';
                    $_data['p_card_cost'] = ($_data['order_amount']-$_data['channel_cost'])
                        * $this->PCardRate['jumia'] * $_data['rate']; //P卡费用
                    $_data['rate_USD'] = $rateUSD['USD'];//美元汇率
                    $_data['selling_price'] = $_data['order_amount'] / $_data['rate_USD'];//售价奈拉
                    $_data['order_amount_CNY'] = $_data['selling_price'] * $_data['rate'];//总售价CNY
                    break;
                case ChannelAccountConst::channel_Vova: //voma
                    $vovaAccount = VovaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$vovaAccount ? $vovaAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['vova']; //P卡费用
                    break;
                case ChannelAccountConst::Channel_umka: //umka
                    $umkaAccount = UmkaAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$umkaAccount ? $umkaAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['umka']; //P卡费用
                    break;
                case ChannelAccountConst::channel_CD: //cd
                    $cdAccount = CdAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$cdAccount ? $cdAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['cd']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Newegg: //newegg
                    $neweggAccount = NeweggAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$neweggAccount ? $neweggAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['newegg']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Oberlo: //oberlo
                    $oberloAccount = OberloAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$oberloAccount ? $oberloAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['oberlo']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Zoodmall: //zoodmall
                    $zoodmallAccount = ZoodmallAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$zoodmallAccount ? $zoodmallAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['zoodmall']; //P卡费用
                    break;
                case ChannelAccountConst::channel_Yandex: //yandex
                    $yandexAccount = YandexAccount::get($_data['channel_account_id']);
                    $_data['account_code'] =$yandexAccount ? $yandexAccount->code : '';
                    $_data['channel_cost_CNY'] = $_data['order_amount']*0.15*$_data['rate'];//渠道成交费
                    $_data['p_card_cost'] = ($_data['order_amount_CNY']-$_data['channel_cost_CNY'])
                        * $this->PCardRate['yandex']; //P卡费用
                    break;
            }
            $_data['team_leader_name'] = '';
            $_data['supervisor_name'] = '';
            if($record->seller_id){
                $user = $this->getLeaderDirector($record->seller_id);
                $_data['team_leader_name'] = $user['team_leader_name'];
                $_data['supervisor_name'] =  $user['supervisor_name'];
            }
            unset( $_data['seller_id']);

            $shipping_name = [];
            $shipping_number = [];
            $package_number = [];
            $package_fee  = [];
            $shipping_fee  = [];
            $warehouse_name  = [];
            $warehouse_type  = [];
            $shipping_time  = [];
            $process_code  = [];
            $_data['warehouse_type']  = '';
            $_data['warehouse_name']  = '';

            $packageIds = $packageService->getPackageIdByOrderId($_data['id']);
            $_data['order_package_num'] = count($packageIds);
            foreach($packageIds as $id){
                $package = $packageModel->where('id', $id)->field('order_id, shipping_id, warehouse_id, shipping_time, shipping_number, process_code, number as package_number, package_fee, shipping_fee')->find();
                if ($package['warehouse_id']) {
                    $warehouse   = Cache::store('warehouse')->getWarehouse($package['warehouse_id']);
                    if (isset($warehouse['type']) && !empty($warehouse['type'])) {
                        $_data['warehouse_type'] = $this->getWarehouseType($warehouse['type']);
                        $warehouse_type[$id] = $this->getWarehouseType($warehouse['type']);
                    }
                    if (isset($warehouse['name']) && !empty($warehouse['name'])) {
                        $_data['warehouse_name'] = $warehouse['name'];
                        $warehouse_name[$id] = $warehouse['name'];
                    }
                }

                $shipping_name[$id] = $package['shipping_id'] ? $shippingMethod->getShippingMethodInfo($package['shipping_id'], 'shortname', ''): '';
                #跟踪号
                //$shipping_number[$id] = ' '.$package['shipping_number'];//避免科学计数法
                $shipping_number[$id] = $package['shipping_number'];//避免科学计数法
                #包裹号
                $package_number[$id] = $this->getPackageNumber($package['package_number']);
                #发货时间
                $shipping_time[$id] = $package['shipping_time'];
                #物流商单号
                $process_code[$id] =  ' '.$package['process_code'];
                $shipping_fee[$id] = 0;//运费
                $package_fee[$id] = 0;//包装费

                $is_merge = $order->isMerge([$id]);//是否合并订单
                if ($is_merge) {
                    if($package['order_id'] == $_data['id']){//运费放在包裹存的订单
                        $shipping_fee[$id] = $package['shipping_fee'];
                        $package_fee[$id] = $package['package_fee'];
                    }
                } else {
                    $shipping_fee[$id] = $package['shipping_fee'];
                    $package_fee[$id] = $package['package_fee'];
                }

                $_data['package_fee']  += $package_fee[$id];
                $_data['shipping_fee']  += $shipping_fee[$id];

            }


            $_data['profit'] = $this->getPlatformOrderProfit($_data['channel_id'],$_data);
            if(in_array($_data['order_number'],$orderNumbers)){
                $_data['order_amount'] = 0;
                $_data['channel_cost'] = 0;
                $_data['order_amount_CNY'] = 0;
                $_data['channel_cost_CNY'] = 0;
                $_data['p_card_cost'] = 0;
                $_data['first_fee'] = 0;
                $_data['profit'] = 0;
            }else{
                $orderNumbers[] = $_data['order_number'];
            }

            $_data['order_amount_CNY'] =  sprintf("%.4f",   $_data['order_amount_CNY']);
            $_data['channel_cost_CNY'] =  sprintf("%.4f",   $_data['channel_cost_CNY']);
            $_data['p_card_cost'] =  sprintf("%.4f",   $_data['p_card_cost']);
            $_data['mc_fee_CNY'] =  sprintf("%.4f",   $_data['mc_fee_CNY']);
            $_data['profit'] =  sprintf("%.4f",   $_data['profit']);

            if($type==2 && count($shipping_name)>1 && count($packageIds)>1){ //导出多品的情况
                $_data['shipping_name']  = '-';
                $_data['shipping_number']  = '-';
                $_data['package_number']  = '-';
                $_data['process_code']  = '-';
                $data[] = $_data;
                foreach($shipping_name as $k => $value){
                    $package_data = $this->initFieldValue($_data['channel_id'], $_data);
                    $package_data['shipping_name']  = $shipping_name[$k];
                    $package_data['shipping_number']  = $shipping_number[$k];
                    $package_data['package_number']  = $package_number[$k];
                    $package_data['package_fee']  = $package_fee[$k];
                    $package_data['shipping_fee']  = $shipping_fee[$k];
                    $package_data['process_code']  = $process_code[$k];
                    $package_data['shipping_time']  = $shipping_time[$k] ? date('Y-m-d',$shipping_time[$k]): '未发货';
                    $detail = $this->getDetail($k);
                    $package_data['goods_cost'] = sprintf("%.4f",  $detail['cost']);
                    $package_data['sku_count'] = $detail['quantity'];
                    $package_data['warehouse_type'] = isset($warehouse_type[$k]) ? $warehouse_type[$k] : '';
                    $package_data['warehouse_name'] = isset($warehouse_name[$k]) ? $warehouse_name[$k] : '';
                    $data[] = $package_data;
                    unset($package_data);
                }
            } else {
                $shipping_name = array_diff($shipping_name, array(''));
                $shipping_number = array_diff($shipping_number, array(''));
                $package_number = array_diff($package_number, array(''));
                $process_code = array_diff($process_code, array(''));
                $_data['shipping_name']  = implode(',', $shipping_name);
                $_data['shipping_number']  = implode(',', $shipping_number);
                $_data['package_number']  =implode(',', $package_number);
                $_data['process_code']  =implode(',', $process_code);
                $data[] = $_data;
            }
            if (!empty($title)) {
                foreach ($data as $key => $value) {
                    $temp = [];
                    foreach ($title as $k => $v) {
                        $temp[$v] = $value[$v];
                    }
                    $info[] = $temp;
                }
                unset($data);
            }
            unset($_data);
        }
        if (!empty($title)) {
            return $info;
        }else{
            return $data;
        }
    }

    private function getWarehouseType($type)
    {
        switch ($type){
            case 1:
                $warehouse_type = '本地仓';
                break;
            case 2:
                $warehouse_type = '海外仓';
                break;
            case 3:
                $warehouse_type = '4px';
                break;
            case 4:
                $warehouse_type = 'winit';
                break;
            case 5:
                $warehouse_type = 'fba';
                break;
            default;
                $warehouse_type = '';
        }

        return $warehouse_type;

    }
    /*
     * 获取包裹成本
     */
    private function getDetail($packageId)
    {
        $cost = 0;
        $quantity = 0;
        $orderDetailModel = new OrderDetailModel();
        $orderDetail = $orderDetailModel->where('package_id', strval($packageId))->field('sku_quantity, sku_cost')->select();
        foreach($orderDetail as $item){
            $cost += $item['sku_quantity']*$item['sku_cost'];
            $quantity += $item['sku_quantity'];
        }
        return  array('cost'=>sprintf('%.2f', $cost), 'quantity'=>$quantity);
    }



    /**
     * 获取销售员组长以及主管
     * @return array
     */
    public function getLeaderDirector($userId)
    {
        $data = [];
        $departmentUserMapService = new DepartmentUserMapService();
        $department_ids = $departmentUserMapService->getDepartmentByUserId($userId);
        $director_id = [];
        $leader_id = [];
        foreach ($department_ids as $d => $department) {
            if(!empty($department)){
                $_leader_id = $departmentUserMapService->getGroupLeaderByChannel($department);
                if(!empty($_leader_id)){
                    foreach($_leader_id as $id){
                        array_push($leader_id, $id);
                    }
                }
                $_director_id = $departmentUserMapService->getDirector($department);
                if(!empty($_director_id)){
                    foreach($_director_id as $id){
                        array_push($director_id, $id);
                    }
                }
            }
        }
        $leader = [];
        foreach ($leader_id as $v) {
            $realname = cache::store('user')->getOneUserRealname($v);
            array_push($leader, $realname);
        }
        $director = [];
        foreach ($director_id as $v) {
            $realname = cache::store('user')->getOneUserRealname($v);
            array_push($director, $realname);
        }
        $data['team_leader_name'] = !empty($leader) ? implode(', ', $leader) : '';
        $data['supervisor_name'] = !empty($director) ? implode(', ', $director): '';
        return $data;
    }

    /**
     * @return int|string
     */
    public function searchCount(&$params){
        $orderModel = new Order;
//        if(isset($params['warehouse_id']) && $params['warehouse_id']){
            $orderModel->join('`order_detail` detail' , '`detail`.`order_id` = `order`.`id`', 'left');
            $orderModel->join('`order_package` package' , '`package`.`id` = `detail`.`package_id`', 'left');
//        }
        return $orderModel->where($this->getSearchCondition($params)) ->group('order.id')->count();
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    protected function getSearchCondition(&$params)
    {
        date_default_timezone_set("PRC");
        $condition = [];
        $channelId = $this->getParameter($params,'channel_id','');
        if(!$channelId){
            throw new Exception('未设置查询平台id',400);
        }
        $condition['order.channel_id'] = $channelId;
        $siteCode = $this->getParameter($params,'site_code','');
        if($siteCode) $condition['order.site_code'] = $siteCode;
        $acctId = $this->getParameter($params,'account_id','');
        if($acctId) {
            $condition['order.channel_account_id'] = $acctId;
        }
        $acctCode = $this->getParameter($params,'account_code','');
        if($acctCode) {
            $condition['account.code'] = $acctCode;
        }
        $wareHouseId = $this->getParameter($params,'warehouse_id','');
        if($wareHouseId){
            $condition['package.warehouse_id'] = $wareHouseId;
        }
        $timeField = $this->getParameter($params,'time_field','');
        $timeStart = $this->getParameter($params,'time_start','');
        $timeEnd = $this->getParameter($params,'time_end','');
        if(!Validate::dateFormat($timeStart,'Y-m-d') || !Validate::dateFormat($timeEnd,'Y-m-d')){
            throw new Exception('必须设置起止时间(如:2017-01-01)',400);
        }
        $timeStart = strtotime($timeStart);
        $timeEnd = strtotime($timeEnd)+ (3600*24-1);
        if($timeStart > $timeEnd){
            throw new Exception('开始时间不能大于结束时间',400);
        }
        if($timeField){
            switch ($timeField){
                case 'shipping_time':
                    $condition['package.shipping_time'] = ['between',[$timeStart,$timeEnd]];
                    break;
                case 'pay_time':
                    $condition['order.pay_time'] = ['between',[$timeStart,$timeEnd]];
                    break;
                default:
                    throw new Exception('不支持的查询时间字段',400);
            }
        }else{
            throw new Exception('查询时间字段未设置',400);
        }
        return $condition;

    }

    /**
     * @param int $params
     * @param int $page
     * @param int $pageSize
     * @param string $timeField
     * @param string $timeSort
     * @return false|\PDOStatement|string|\think\Collection
     */
    protected function doSearch(&$params,$page=1,$pageSize=10, $timeField ='', $timeSort='ASC')
    {
        $condition = $this->getSearchCondition($params);
        $order = '';
        switch ($timeField){
            case 'shipping_time':
                $order = 'package.shipping_time '.$timeSort.', order.id desc';
                break;
            case 'pay_time':
                $order = 'order.pay_time '.$timeSort;
                break;
        }
        $order = $order ? $order.', order.id desc ': $order;
        $orderModel = new Order;

//        if(isset($params['warehouse_id']) && $params['warehouse_id']){
            $orderModel->join('`order_detail` detail' , '`detail`.`order_id` = `order`.`id`', 'left');
            $orderModel->join('`order_package` package' , '`package`.`id` = `detail`.`package_id`', 'left');
//        }

        $fields = '`order`.`id`,'.                 //系统单号
            '`order`.`channel_id`,'.                  //系统单号
            'sum(`detail`.`sku_quantity`) as sku_count,'.                  //系统单号
            '`order`.`channel_account_id`,'.          //平台账号id
            '`order`.`order_number`,'.                  //系统单号
            '`order`.`site_code`,'.                     //站点编码
            '`order`.`seller_id`,'.                     //销售员id
            '`order`.`seller` AS seller_name,'.         //销售员姓名
            '`order`.`channel_order_number`,'.          //平台单号
            '`order`.`pay_time`,'.                      //支付时间
            '`order`.`type`,'.                      //0-渠道  1-手工 2-刷单
            'order.cost,'.                              //商品成本
            '`package`.`shipping_time`,'.           //发货时间
            '`order`.`pay_fee` as order_amount ,'.                  //原币售价（支付金额）
            '`order`.`channel_cost`,'.                  //原币平台手续费
            '`order`.`currency_code`,'.                 //当前币种
            '`order`.`rate`,'.                          //当前汇率
            '(`order`.`pay_fee` * `order`.`rate`) AS order_amount_CNY,'.   //售价人民币（总支付费用）
            '(`order`.`channel_cost` * `order`.`rate`) AS channel_cost_CNY,'. //平台费用人民币
            '(`order`.`first_fee`+`order`.`tariff`) AS first_fee,'. //头程费
//            '`addr`.`country_code`,'.     //订单地址国家编码
//            '`addr`.`zipcode`,'.          //订单地址邮编
            '`order`.`paypal_fee` as mc_fee,' .           //paypal费用
            '`order`.`estimated_fee`';          //估计运费
        if($params['channel_id'] == ChannelAccountConst::channel_wish){
            $orderModel->join('`order_address` addr'    , '`addr`.`order_id` = `order`.`id`', 'left');
            $fields .=  ',`addr`.`country_code`, `addr`.`zipcode`';         //订单地址邮编
        }
        $orderModel->field($fields)
                    ->where($condition)
                    ->order($order)
                    ->group('order.id');
        return $orderModel->page($page,$pageSize)->select();
    }


    /**
     * 获取订单货品
     * @param int $order_id
     * @return array
     */
    function getOrderSkus($order_id = 0){
        $skus = OrderDetailModel::field('sku,sku_id,sku_quantity')->where(['order_id'=>$order_id])->select();
        if($skus){
            foreach ($skus as $key=>$vo){
                $ware_result = WarehouseGoodsModel::field('per_cost')->where(['sku_id'=>$vo['sku_id']])->find();
                $skus[$key]['cost'] = $ware_result['per_cost']?$ware_result['per_cost']:0;
            }

        }
        return $skus;
    }

}