<?php
namespace app\report\service;

use app\common\cache\Cache;
use app\common\model\aliexpress\AliexpressSettlement;
use app\common\model\wish\WishSettlement;
use app\common\service\ChannelAccountConst;
use app\common\service\CommonQueuer;
use app\common\service\Excel;
use app\report\model\ReportExportFiles;
use app\report\queue\SettlementExport;
use app\report\validate\FileExportValidate;
use think\Exception;


/**
 * Created by PhpStorm.
 * User: lin
 * time 2018/11/26 18:31
 */
class Settlement
{

    public static function getSettleWhere($param)
    {
        $where = [];
        $start_time = param($param,'date_b');
        $end_time = param($param,'date_e');
        $timeWhere = 'a.payment_time';
        if ($timeType = param($param, 'time_type')) {
            switch ($timeType){
                case 'payment_time':
                    $timeWhere = 'a.payment_time';
                    break;
                case 'shipping_time':
                    $timeWhere = 'a.shipping_time';
                    break;
            }
        }
        if ($timeWhere) {
            if($start_time && $end_time){
                $where[$timeWhere] = [['>=', $start_time],['<=',$end_time]];
            }else {
                if ($start_time) {
                    $where[$timeWhere] = ['>=', $start_time];
                }
                if ($end_time) {
                    $where[$timeWhere] = ['<=', $end_time];
                }
            }
        }
        if (isset($param['account_id']) && $param['account_id']) {
            $where['a.account_id'] = intval($param['account_id']);
        }
        if (isset($param['loan_period'])) {
            $where['a.account_period_week'] = intval($param['loan_period']) ==16 ? ['>=',16] : intval($param['loan_period']);
        }
        if (isset($param['has_substitute_fee']) && is_numeric($param['has_substitute_fee'])){
            $where['a.has_substitute_fee'] = intval($param['has_substitute_fee']);
        }
        $where['a.payment_amount'] = ['>', 0];
        return $where;
    }

    public static function getWishSettleWhere($params)
    {
        $where = [];
        $start_time = param($params,'date_b');
        $end_time = param($params,'date_e');
        $timeWhere = '';
        if ($timeType = param($params, 'time_type')) {
            switch ($timeType){
                case 'payment_time':
                    $timeWhere = 'a.payment_time';
                    break;
                case 'shipping_time':
                    $timeWhere = 'a.shipping_time';
                    break;
            }
        }
        if ($timeWhere) {
            if($start_time && $end_time){
                $where[$timeWhere] = [['>=', $start_time],['<=',$end_time]];
            }else {
                if ($start_time) {
                    $where[$timeWhere] = ['>=', $start_time];
                }
                if ($end_time) {
                    $where[$timeWhere] = ['<=', $end_time];
                }
            }
        }
        if (isset($params['account_id']) && $params['account_id']) {
            $where['a.account_id'] = intval($params['account_id']);
        }
        if (isset($params['loan_period'])) {
            $where['a.account_period_week'] = intval($params['loan_period']) ==16 ? ['>=',16] : intval($params['loan_period']);
        }
        return $where;
    }
    public function applyExport($params,$user)
    {
        $exportFileName = $this->getExportFileName($params);
        $model = new ReportExportFiles();
        $model->applicant_id = $user['user_id'];
        $model->apply_time = time();
        $model->export_file_name = $exportFileName. '.xlsx';
        $model->status = 0;
        if (!$model->save()) {
            throw new Exception('导出请求创建失败');
        }

        $params['file_name'] = $exportFileName;
        $params['apply_id'] = $model->id;

        $queue = new CommonQueuer(SettlementExport::class);
        $queue->push($params);
//        $queue = new SettlementExport();
//        $queue->execute($params);
    }

    public function formatWishData($regularOrder,$regularTransfer,$refundOrder,$refundTransfer)
    {
        $data = [];
        $order_quantity = $order_amount = $transfer_amount = $loan_amount = $correlative_amount = $loan_period = $refund_amount = 0;
        $boolSixteen = false;
        foreach ($regularOrder as $v){
            $regular = [
                'loan_period'=>0,
                'order_quantity'=>0,
                'order_amount'=>0,
                'transfer_amount'=>0,
                'loan_amount'=>0,
                'correlative_amount'=>0,
                'refund_amount'=>0,
                'withhold_amount'=>0,
            ];
            if ($v['loan_period'] > 16) {
                $loan_period = '16周以上';
                $order_quantity += $v['order_quantity'];
                $order_amount += $v['order_amount'];
                foreach ($regularTransfer as $vv){
                    if ($v['loan_period'] == $vv['loan_period']) {
                        $transfer_amount += $vv['transfer_amount'];
                        $loan_amount += $vv['loan_amount'];
                        $correlative_amount += $vv['correlative_amount'];
                        $refund_amount += $vv['refund_amount'];
                    }
                }
                $boolSixteen = true;
            }else{
                $regular['loan_period'] = $v['loan_period'];
                $regular['order_quantity'] = $v['order_quantity'];
                $regular['order_amount'] = $v['order_amount'];
                foreach ($regularTransfer as $vv){
                    if ($v['loan_period'] == $vv['loan_period']) {
                        $regular['transfer_amount'] = $vv['transfer_amount'];
                        $regular['loan_amount'] = $vv['loan_amount'];
                        $regular['correlative_amount'] = $vv['correlative_amount'];
                        $regular['refund_amount'] = $vv['refund_amount'];
                    }
                }
                $data[] = $regular;
            }
        }
        if ($boolSixteen) {
            $regular = [];
            $regular['loan_period'] = $loan_period;
            $regular['order_quantity'] = $order_quantity;
            $regular['order_amount'] = $order_amount;
            $regular['transfer_amount'] = $transfer_amount;
            $regular['loan_amount'] = $loan_amount;
            $regular['correlative_amount'] = $correlative_amount;
            $regular['refund_amount'] = $refund_amount;
            $data[] = $regular;
        }
        $refund = [
            [
                'loan_period' => '未放款-未退款订单',
                'order_quantity' => 0,
                'transfer_amount' => 0,
                'loan_amount' => 0,
                'correlative_amount' => 0,
                'refund_amount' => 0,
                'withhold_amount' => 0,
                'order_amount' => 0
            ],
            [
                'loan_period' => '未放款-已退款订单',
                'order_quantity' => 0,
                'transfer_amount' => 0,
                'loan_amount' => 0,
                'correlative_amount' => 0,
                'refund_amount' => 0,
                'withhold_amount' => 0,
                'order_amount' => 0
            ],
            [
                'loan_period' => '退款订单',
                'order_quantity' => 0,
                'transfer_amount' => 0,
                'loan_amount' => 0,
                'correlative_amount' => 0,
                'refund_amount' => 0,
                'withhold_amount' => 0,
                'order_amount' => 0
            ],
        ];
        foreach ($refundOrder as $k){
            switch ($k['is_refund']) {
                case '00':
                    $this->mergeWishRefund($k,$refund[0],$refundTransfer);
                    break;
                case '01':
                    $this->mergeWishRefund($k,$refund[1],$refundTransfer);
                    break;
                case '11':
                    $this->mergeWishRefund($k,$refund[2],$refundTransfer);
                    break;
            }
        }
        foreach ($refund as $value){
            $data[] = $value;
        }
        $totalOrderQuantity = $totalOrderAmount = $totalLoanAmount = $totalTransferAmount = $totalCorrelativeAmount = $totalRefundAmount = 0;
        foreach ($data as $item){
            $totalOrderQuantity += floatval(param($item,'order_quantity',0));
            $totalOrderAmount += floatval(param($item,'order_amount',0));
            $totalLoanAmount += floatval(param($item,'loan_amount',0));
            $totalRefundAmount += floatval(param($item,'refund_amount',0));
            $totalTransferAmount += floatval(param($item,'transfer_amount',0));
            $totalCorrelativeAmount += floatval(param($item,'correlative_amount',0));
        }
        foreach ($data as &$set){
            $set['real_loan_proportion'] = param($set,'order_amount',0) >0 ? (floatval($set['loan_amount']) / floatval($set['order_amount']))*100 : 0;
            $set['order_amount_proportion'] = $totalOrderAmount >0 ? (floatval($set['order_amount']) / $totalOrderAmount)*100 : 0;
            $set['order_quantity_proportion'] = $totalOrderQuantity >0 ? (floatval($set['order_quantity']) /$totalOrderQuantity)*100 : 0;
            $set['transfer_amount_proportion'] = param($set,'order_amount',0)  >0 ? (floatval($set['transfer_amount']) /floatval($set['order_amount']))*100 : 0;
            $set['refund_amount_proportion'] = param($set,'order_amount',0) >0 ? (floatval($set['refund_amount']) /floatval($set['order_amount']))*100 : 0;

        }
        $totalOrderAmountProportion = $totalOrderQuantityProportion = 0;
        $totalTotalLoanProportion = $totalOrderAmount > 0 ? ($totalLoanAmount / $totalOrderAmount) * 100 :0;
        $totalTotalRefundProportion = $totalOrderAmount > 0 ? ($totalRefundAmount / $totalOrderAmount) * 100 :0;
        $totalTransferAmountProportion = $totalOrderAmount >0 ? ($totalTransferAmount / $totalOrderAmount) * 100 : 0;
        foreach ($data as $s){
            $totalOrderAmountProportion += $s['order_amount_proportion'];
            $totalOrderQuantityProportion += $s['order_quantity_proportion'];
        }
        $total = [];
        $total['loan_period'] = '汇总';
        $total['order_quantity'] = $totalOrderQuantity;
        $total['order_amount'] = $totalOrderAmount;
        $total['loan_amount'] = $totalLoanAmount;
        $total['refund_amount'] = $totalRefundAmount;
        $total['transfer_amount'] = $totalTransferAmount;
        $total['correlative_amount'] = $totalCorrelativeAmount;
        $total['real_loan_proportion'] = $totalTotalLoanProportion;
        $total['refund_amount_proportion'] = $totalTotalRefundProportion;
        $total['order_amount_proportion'] = $totalOrderAmountProportion;
        $total['order_quantity_proportion'] = $totalOrderQuantityProportion;
        $total['transfer_amount_proportion'] = $totalTransferAmountProportion;
        $data[] = $total;
        /** 格式化数据 */
        foreach ($data as &$ss){
            $ss['order_quantity'] = number_format(param($ss,'order_quantity',0),0);
            $ss['order_amount'] = number_format(param($ss,'order_amount',0),2);
            $ss['transfer_amount'] = number_format(param($ss,'transfer_amount',0),2);
            if (floatval(param($ss,'refund_amount',0))) {
                $ss['loan_amount'] = number_format(param($ss,'loan_amount',0),2) . '|' . number_format(param($ss,'refund_amount',0),2);
            }else{
                $ss['loan_amount'] = number_format(param($ss,'loan_amount',0),2);
            }
            if (floatval(param($ss,'refund_amount_proportion',0))) {
                $ss['real_loan_proportion'] = number_format(param($ss,'real_loan_proportion',0),2) . '%' .'|' . number_format(param($ss,'refund_amount_proportion',0),2) . '%';
                $ss['refund_amount_proportion'] = number_format(param($ss,'refund_amount_proportion',0),2). '%';
            }else{
                $ss['real_loan_proportion'] = number_format(param($ss,'real_loan_proportion',0),2,'.','') . '%';
            }
            $ss['correlative_amount'] = number_format(param($ss,'correlative_amount',0),2);
            $ss['order_amount_proportion'] = number_format(param($ss,'order_amount_proportion',0),2) . '%';
            $ss['order_quantity_proportion'] = number_format(param($ss,'order_quantity_proportion',0),2) . '%';
            $ss['transfer_amount_proportion'] = number_format(param($ss,'transfer_amount_proportion',0),2) . '%';
        }
        return $data;
    }

    public function getRegularOrder($publicWhere,$whereRegular)
    {
        $settleModel = new WishSettlement();
        $fieldRegularOrder = [
            'a.account_period_week loan_period',
            'count(1) order_quantity',
            'sum(a.payment_amount*a.to_cny_rate) order_amount'
        ];
        $regularOrder  =$settleModel->alias('a')->field($fieldRegularOrder)->where($whereRegular)->where($publicWhere)->group('a.account_period_week')->order('a.account_period_week')->select();
        return $regularOrder;
    }
    public function getAliRegularOrder($publicWhere,$whereRegular)
    {
        $settleModel = new AliexpressSettlement();
        $fieldRegularOrder = [
            'a.has_substitute_fee has_substitute_fee',
            'a.account_period_week loan_period',
            'count(1) order_quantity',
            'sum(a.payment_amount*a.to_cny_rate) order_amount'
        ];
        $regularOrder  =$settleModel->alias('a')->field($fieldRegularOrder)->where($whereRegular)
            ->where($publicWhere)->group('a.account_period_week,a.has_substitute_fee')
            ->order('a.account_period_week')->select();
        return $regularOrder;
    }
    public function getRegularTransfer($publicWhere,$whereRegular)
    {
        $settleModel = new WishSettlement();
        $fieldRegularTransfer = [
            'a.account_period_week loan_period',
            'SUM(b.amount * c.to_cny_rate) transfer_amount',
            'SUM(CASE WHEN b.wish_transaction_type_id=1 THEN b.amount * c.to_cny_rate ELSE 0 END ) loan_amount',
            'SUM(CASE WHEN b.wish_transaction_type_id in (2,8) THEN b.amount * c.to_cny_rate ELSE 0 END ) refund_amount',
            'SUM(CASE WHEN b.wish_transaction_type_id in (7,5,6,14,15,16,289,680,9,10,11,12,17,18,20,21,22,23,37,39,43,47,53,111,204,296,312,313,328,694,718) THEN b.amount * c.to_cny_rate ELSE 0 END ) correlative_amount',
        ];

        $regularTransfer = $settleModel->alias('a')->field($fieldRegularTransfer)->where($whereRegular)->where($publicWhere)
            ->join('wish_settlement_report_detail b','a.wish_order_id=b.wish_order_id','left')->
            join('wish_settlement_report c','c.id = b.wish_settlement_report_id','left')->
            group('a.account_period_week')->order('a.account_period_week')->select();
        return $regularTransfer;
    }
    public function getAliRegularTransfer($publicWhere,$whereRegular)
    {
        $settleModel = new AliexpressSettlement();
        $fieldRegularTransfer = [
            'a.has_substitute_fee has_substitute_fee',
            'a.account_period_week loan_period',
            'SUM(b.amount * b.to_cny_rate) transfer_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id = 5 THEN b.amount * b.to_cny_rate ELSE 0 END ) loan_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id in (1,11) THEN b.amount * b.to_cny_rate ELSE 0 END ) refund_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id in (3,10) THEN b.amount*b.to_cny_rate ELSE 0 END ) withhold_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id in (2,4,6,8,14,13) THEN b.amount * b.to_cny_rate ELSE 0 END ) correlative_amount',
        ];

        $regularTransfer = $settleModel->alias('a')->field($fieldRegularTransfer)->where($whereRegular)->where($publicWhere)
            ->where('b.wait_delete', '=','0')
            ->join('aliexpress_settlement_report_detail b','a.aliexpress_order_id=b.aliexpress_order_id','left')->
            group('a.account_period_week,a.has_substitute_fee')->order('a.account_period_week')->select();
        return $regularTransfer;
    }
    public function getRefundOrder($publicWhere,$whereRefund)
    {
        $settleModel = new WishSettlement();
        $fieldRefundOrder = [
            'CONCAT(a.transfer_time>0,a.refund_time_by_report>0) is_refund',
            'count(1) order_quantity',
            'sum(a.payment_amount * a.to_cny_rate) order_amount'
        ];
        $refundOrder = $settleModel->alias('a')->field($fieldRefundOrder)->where($publicWhere)->where($whereRefund)->where(function ($query){
            $query->where('a.transfer_time','=',0)->whereOr(function ($query2){
                $query2->where([
                    'a.transfer_time' => ['>',0],
                    'a.refund_time_by_report' => ['>',0]
                ]);
            });
        })->group('CONCAT(a.transfer_time>0,a.refund_time_by_report>0)')->select();
        return $refundOrder;
    }

    public function getAliRefundOrder($publicWhere,$whereRefund)
    {
        $settleModel = new AliexpressSettlement();
        $fieldRefundOrder = [
            'a.has_substitute_fee has_substitute_fee',
            'CONCAT(a.transfer_time_by_report>0,a.refund_time_by_report>0) is_refund',
            'count(1) order_quantity',
            'sum(a.payment_amount * a.to_cny_rate) order_amount'
        ];
        $refundOrder = $settleModel->alias('a')->field($fieldRefundOrder)->where($publicWhere)->where($whereRefund)->where(function ($query){
            $query->where('a.transfer_time_by_report','=',0)->whereOr(function ($query2){
                $query2->where([
                    'a.transfer_time_by_report' => ['>',0],
                    'a.refund_time_by_report' => ['>',0]
                ]);
            });
        })->group('CONCAT(a.transfer_time_by_report>0,a.refund_time_by_report>0),a.has_substitute_fee')->select();
        return $refundOrder;
    }

    public function getRefundTransfer($publicWhere,$whereRefund)
    {
        $settleModel = new WishSettlement();
        $fieldRefundTransfer = [
            'CONCAT(a.transfer_time>0,a.refund_time_by_report>0) is_refund',
            'SUM(b.amount * c.to_cny_rate) transfer_amount',
            'SUM(CASE WHEN b.wish_transaction_type_id=1 THEN b.amount * c.to_cny_rate ELSE 0 END ) loan_amount',
            'SUM(CASE WHEN b.wish_transaction_type_id in (2,8) THEN b.amount * c.to_cny_rate ELSE 0 END ) refund_amount',
            'SUM(CASE WHEN b.wish_transaction_type_id in (7,5,6,14,15,16,289,680,9,10,11,12,17,18,20,21,22,23,37,39,43,47,53,111,204,296,312,313,328,694,718) THEN b.amount * c.to_cny_rate ELSE 0 END ) correlative_amount'
        ];
        $refundTransfer = $settleModel->alias('a')->field($fieldRefundTransfer)->where($publicWhere)->where($whereRefund)->where(function ($query){
            $query->where('a.transfer_time','=',0)->whereOr(function ($query2){
                $query2->where([
                    'a.transfer_time' => ['>',0],
                    'a.refund_time_by_report' => ['>',0]
                ]);
            });
        })->join('wish_settlement_report_detail b','a.wish_order_id=b.wish_order_id','left')
            ->join('wish_settlement_report c','c.id = b.wish_settlement_report_id','left')
            ->group('CONCAT(a.transfer_time>0,a.refund_time_by_report>0)')->select();
        return $refundTransfer;
    }

    public function getAliRefundTransfer($publicWhere,$whereRefund)
    {
        $settleModel = new AliexpressSettlement();
        $fieldRefundTransfer = [
            'a.has_substitute_fee has_substitute_fee',
            'CONCAT(a.transfer_time_by_report>0,a.refund_time_by_report>0) is_refund',
            'SUM(b.amount * b.to_cny_rate) transfer_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id=5 THEN b.amount * b.to_cny_rate ELSE 0 END ) loan_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id in (1,11) THEN b.amount * b.to_cny_rate ELSE 0 END ) refund_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id in (3,10) THEN b.amount * b.to_cny_rate ELSE 0 END ) withhold_amount',
            'SUM(CASE WHEN b.aliexpress_transaction_type_id in (2,4,6,8,14,13) THEN b.amount * b.to_cny_rate ELSE 0 END ) correlative_amount'
        ];
        $refundTransfer = $settleModel->alias('a')->field($fieldRefundTransfer)->where($publicWhere)->where($whereRefund)->where(function ($query){
            $query->where('a.transfer_time_by_report','=',0)->whereOr(function ($query2){
                $query2->where([
                    'a.transfer_time_by_report' => ['>',0],
                    'a.refund_time_by_report' => ['>',0]
                ]);
            });
        })->where(function ($query3){
            $query3->where('b.wait_delete', '=', '0')->whereOr(function ($query4){
                $query4->where('b.wait_delete', null);
            });
        })->join('aliexpress_settlement_report_detail b','a.aliexpress_order_id=b.aliexpress_order_id','left')
            ->group('CONCAT(a.transfer_time_by_report>0,a.refund_time_by_report>0),a.has_substitute_fee')->select();
        return $refundTransfer;
    }

    public function getAliData($params)
    {
        $data = [];
        $publicWhere = $this->getSettleWhere($params);
        //#正常周期(已放款-未退款)===>订单量、订单金额
        $whereRegular = [
            'a.shipping_time' => ['>',0],
            'a.transfer_time_by_report' => ['>',0],
            'a.refund_time_by_report' => ['=',0],
        ];
        $whereRefund = [
            'a.shipping_time' => ['>',0],
        ];
        if (isset($publicWhere['a.shipping_time'])) {
            unset($whereRegular['a.shipping_time']);
            unset($whereRefund['a.shipping_time']);
        }
        $regularOrder = $this->getAliRegularOrder($publicWhere,$whereRegular);
        //#正常周期(已放款-未退款)===>转账金额、放款金额、订单相关费用金额合计
        $regularTransfer = $this->getAliRegularTransfer($publicWhere,$whereRegular);
//                未放款-未退款、未放款-已退款、已放款-已退款==>订单量、订单金额
        $refundOrder = $this->getAliRefundOrder($publicWhere,$whereRefund);
//                未放款-未退款、未放款-已退款、已放款-已退款===>转账金额、放款金额、订单相关费用金额合计
        $refundTransfer = $this->getAliRefundTransfer($publicWhere,$whereRefund);
        if (($refundTransfer || $refundOrder) || $regularOrder && $regularTransfer) {
            $data = $this->formatAliexpressData($regularOrder,$regularTransfer,$refundOrder,$refundTransfer);
        }
        return $data;
    }

    public function getWishData($params)
    {
        $data = [];
        $publicWhere = $this->getWishSettleWhere($params);
        //#正常周期(已放款-未退款)===>订单量、订单金额
        $whereRegular = [
            'a.shipping_time' => ['>',0],
            'a.transfer_time' => ['>',0],
            'a.refund_time_by_report' => ['=',0],
        ];
        $whereRefund = [
            'a.shipping_time' => ['>',0],
        ];
        if (isset($publicWhere['a.shipping_time'])) {
            unset($whereRegular['a.shipping_time']);
            unset($whereRefund['a.shipping_time']);
        }
        $regularOrder = $this->getRegularOrder($publicWhere,$whereRegular);
        //#正常周期(已放款-未退款)===>转账金额、放款金额、订单相关费用金额合计
        $regularTransfer = $this->getRegularTransfer($publicWhere,$whereRegular);
//                未放款-未退款、未放款-已退款、已放款-已退款==>订单量、订单金额
        $refundOrder = $this->getRefundOrder($publicWhere,$whereRefund);
//                未放款-未退款、未放款-已退款、已放款-已退款===>转账金额、放款金额、订单相关费用金额合计
        $refundTransfer = $this->getRefundTransfer($publicWhere,$whereRefund);
        if (($refundTransfer || $refundOrder) || $regularOrder && $regularTransfer) {
            $data = $this->formatWishData($regularOrder,$regularTransfer,$refundOrder,$refundTransfer);
        }
        return $data;
    }

    public function formatAliexpressData($regularOrder,$regularTransfer,$refundOrder,$refundTransfer)
    {
        $res = [];
        /**  将四个变量合成一个数组，代扣和非代扣不分开*/
        $data = $this->getTableData($regularOrder, $regularTransfer, $refundOrder, $refundTransfer);
        /** 将代扣和非代扣，分开处理 */
        $h_data = [];
        $hn_data = [];
        foreach ($data as $item){
            if ($item['has_substitute_fee'] == 1) {
                $h_data[] = $item;
            }else{
                $hn_data[] = $item;
            }
        }
        /** 获取代扣和非代扣各自的数据，并计算各单元比例 */
        $h_t_data = $this->getAliTotalData($h_data);
        $hn_t_data = $this->getAliTotalData($hn_data);
        /** 格式化代扣和非代扣数据，并计算各自汇总比例 */
        $this->formatAliTableData($h_data);
        $this->formatAliTableData($hn_data);
        $res['h_data'] = $h_data;
        $res['hn_data'] = $hn_data;
        /**
         *  获取代扣和非代扣汇总后的数据 sum 并格式化
         */
        $sum = $this->getAliSumData($h_t_data,$hn_t_data);
        $res['sum'][] = $sum;
        return $res;
    }

    public function getAliSumData($h_t_data,$hn_t_data)
    {
        /** 初始化汇总数据 */

        $sum = [
            'loan_period' => '汇总',
            'order_quantity' => ($h_t_data['order_quantity'] + $hn_t_data['order_quantity']) != 0 ? ($h_t_data['order_quantity'] + $hn_t_data['order_quantity']) :0,
            'order_amount' => ($h_t_data['order_amount'] + $hn_t_data['order_amount']) != 0 ? ($h_t_data['order_amount'] + $hn_t_data['order_amount']) :0,
            'loan_amount' => ($h_t_data['loan_amount'] + $hn_t_data['loan_amount']) != 0 ? ($h_t_data['loan_amount'] + $hn_t_data['loan_amount']) :0,
            'refund_amount' => ($h_t_data['refund_amount'] + $hn_t_data['refund_amount']) != 0 ? ($h_t_data['refund_amount'] + $hn_t_data['refund_amount']) :0,
            'withhold_amount' => ($h_t_data['withhold_amount'] + $hn_t_data['withhold_amount']) != 0 ? ($h_t_data['withhold_amount'] + $hn_t_data['withhold_amount']) :0,
            'transfer_amount' =>($h_t_data['transfer_amount'] + $hn_t_data['transfer_amount']) != 0 ? ($h_t_data['transfer_amount'] + $hn_t_data['transfer_amount']) :0,
            'correlative_amount' =>($h_t_data['correlative_amount'] + $hn_t_data['correlative_amount']) != 0 ? ($h_t_data['correlative_amount'] + $hn_t_data['correlative_amount']) :0,
        ];

        /** 计算汇总比例 */

        $sum['real_loan_proportion'] = param($sum,'order_amount',0) > 0 ? (param($sum,'loan_amount',0)/ param($sum,'order_amount',0))*100 : 0;
        $sum['refund_amount_proportion'] = param($sum,'order_amount',0) > 0 ? (param($sum,'refund_amount',0)/ param($sum,'order_amount',0))*100 : 0;
        $sum['order_amount_proportion'] = param($sum,'order_amount',0) > 0 ? (param($sum,'order_amount',0)/ param($sum,'order_amount',0))*100 : 0;
        $sum['order_quantity_proportion'] = param($sum,'order_quantity',0) > 0 ? (param($sum,'order_quantity',0)/ param($sum,'order_quantity',0))*100 : 0;
        $sum['transfer_amount_proportion'] = param($sum,'order_amount',0) > 0 ? (param($sum,'transfer_amount',0)/ param($sum,'order_amount',0))*100 : 0;

        /** 格式化 汇总数据 */

        $sum['loan_amount'] = number_format($sum['loan_amount'],2,'.','');
        $sum['order_amount'] = number_format($sum['order_amount'],2,'.','');
        $sum['refund_amount'] = number_format($sum['refund_amount'],2,'.','');
        $sum['transfer_amount'] = number_format($sum['transfer_amount'],2,'.','');
        $sum['correlative_amount'] = number_format($sum['correlative_amount'],2,'.','');
        $sum['withhold_amount'] = number_format($sum['withhold_amount'],2,'.','');
        if ($sum['refund_amount'] !=0) {
            $sum['loan_amount'] = number_format($sum['loan_amount'],2,'.','') .'|' .number_format($sum['refund_amount'],2,'.','');
        }
        $sum['real_loan_proportion'] = number_format($sum['real_loan_proportion'],2,'.','') ;
        if ($sum['refund_amount_proportion'] !=0) {
            $sum['real_loan_proportion'] = number_format($sum['real_loan_proportion'],2,'.','') .'|' .number_format($sum['refund_amount_proportion'],2,'.','') ;
        }
        $sum['order_amount_proportion'] = number_format($sum['order_amount_proportion'],2,'.','') ;
        $sum['order_quantity_proportion'] = number_format($sum['order_quantity_proportion'],2,'.','') ;
        $sum['transfer_amount_proportion'] = number_format($sum['transfer_amount_proportion'],2,'.','') ;
        return $sum;
    }

    /**
     * 格式化代扣，非代扣数据，保留两位小数
     * @param $data
     */
    public function formatAliTableData(&$data)
    {
        foreach ($data as &$ss){
            $ss['order_quantity'] = number_format(param($ss,'order_quantity',0),0,'.','');
            $ss['order_amount'] = sprintf("%.2f", param($ss,'order_amount',0));
            $ss['transfer_amount'] = sprintf("%.2f", param($ss,'transfer_amount',0));
            $ss['loan_amount'] = sprintf("%.2f", param($ss,'loan_amount',0));
            if (floatval(param($ss,'refund_amount',0))) {
                $ss['loan_amount'] =sprintf("%.2f", param($ss,'loan_amount',0)) . '|' . sprintf("%.2f", param($ss,'refund_amount',0));
            }
            $ss['real_loan_proportion'] = sprintf("%.2f", param($ss,'real_loan_proportion',0));
            if (floatval(param($ss,'refund_amount_proportion',0))) {
                $ss['real_loan_proportion'] =  sprintf("%.2f", param($ss,'real_loan_proportion',0)) .'|' .  sprintf("%.2f", param($ss,'refund_amount_proportion',0));
                $ss['refund_amount_proportion'] = sprintf("%.2f", param($ss,'refund_amount_proportion',0));
            }
            $ss['correlative_amount'] = sprintf("%.2f", param($ss,'correlative_amount',0));
            $ss['withhold_amount'] =sprintf("%.2f", param($ss,'withhold_amount',0));
            $ss['order_amount_proportion'] = sprintf("%.2f", param($ss,'order_amount_proportion',0));
            $ss['order_quantity_proportion'] = sprintf("%.2f", param($ss,'order_quantity_proportion',0));
            $ss['transfer_amount_proportion'] =sprintf("%.2f", param($ss,'transfer_amount_proportion',0));
        }
    }
    /**
     * 获取四个变量合并成一份的数据，代扣，未代扣不分开
     * @param $regularOrder
     * @param $regularTransfer
     * @param $refundOrder
     * @param $refundTransfer
     * @return array
     */
    public function getTableData($regularOrder, $regularTransfer, $refundOrder, $refundTransfer)
    {
        $data = [];
        $order_quantity = $order_amount = $transfer_amount = $loan_amount = $correlative_amount = $loan_period = $refund_amount = $withhold_amount = $has_substitute_fee = 0;
        $boolSixteen = false;
        $boolSixteen_1 = false;
        $regularSixteen_1 = [
            'has_substitute_fee' => 0,
            'loan_period' => 0,
            'order_quantity' => 0,
            'order_amount' => 0,
            'transfer_amount' => 0,
            'loan_amount' => 0,
            'correlative_amount' => 0,
            'refund_amount' => 0,
            'withhold_amount' => 0,
        ];
        foreach ($regularOrder as $v) {
            $regular = [
                'has_substitute_fee' => 0,
                'loan_period' => 0,
                'order_quantity' => 0,
                'order_amount' => 0,
                'transfer_amount' => 0,
                'loan_amount' => 0,
                'correlative_amount' => 0,
                'refund_amount' => 0,
                'withhold_amount' => 0,
            ];
            if ($v['loan_period'] > 16 ) {
                $loan_period = '16周以上';
                foreach ($regularTransfer as $vv) {
                    if ($v['loan_period'] == $vv['loan_period'] && $v['has_substitute_fee'] == $vv['has_substitute_fee'] && (intval($v['has_substitute_fee'])==0)) {
                        $has_substitute_fee = $v['has_substitute_fee'];
                        $order_quantity += $v['order_quantity'];
                        $order_amount += $v['order_amount'];
                        $transfer_amount += $vv['transfer_amount'];
                        $loan_amount += $vv['loan_amount'];
                        $correlative_amount += $vv['correlative_amount'];
                        $refund_amount += $vv['refund_amount'];
                        $withhold_amount += $vv['withhold_amount'];
                        $boolSixteen = true;
                    }elseif ($v['loan_period'] == $vv['loan_period'] && $v['has_substitute_fee'] == $vv['has_substitute_fee'] && (intval($v['has_substitute_fee'])==1)){
                        $regularSixteen_1['has_substitute_fee'] = $v['has_substitute_fee'];
                        $regularSixteen_1['order_quantity'] += $v['order_quantity'];
                        $regularSixteen_1['order_amount'] += $v['order_amount'];
                        $regularSixteen_1['transfer_amount'] += $vv['transfer_amount'];
                        $regularSixteen_1['loan_amount'] += $vv['loan_amount'];
                        $regularSixteen_1['refund_amount'] += $vv['refund_amount'];
                        $regularSixteen_1['withhold_amount'] += $vv['withhold_amount'];
                        $regularSixteen_1['correlative_amount'] += $vv['correlative_amount'];
                        $boolSixteen_1 = true;
                    }
                }

            } else {
                $regular['loan_period'] = $v['loan_period'];
                $regular['order_quantity'] = $v['order_quantity'];
                $regular['order_amount'] = $v['order_amount'];
                $regular['has_substitute_fee'] = $v['has_substitute_fee'];
                foreach ($regularTransfer as $vv) {
                    if ($v['loan_period'] == $vv['loan_period'] && $v['has_substitute_fee'] == $vv['has_substitute_fee']) {
                        $regular['transfer_amount'] = $vv['transfer_amount'];
                        $regular['loan_amount'] = $vv['loan_amount'];
                        $regular['correlative_amount'] = $vv['correlative_amount'];
                        $regular['refund_amount'] = $vv['refund_amount'];
                        $regular['withhold_amount'] = $vv['withhold_amount'];
                    }
                }
                $data[] = $regular;
            }
        }
        if ($boolSixteen) {
            $regular = [];
            $regular['loan_period'] = $loan_period;
            $regular['has_substitute_fee'] = $has_substitute_fee;
            $regular['order_quantity'] = $order_quantity;
            $regular['order_amount'] = $order_amount;
            $regular['transfer_amount'] = $transfer_amount;
            $regular['loan_amount'] = $loan_amount;
            $regular['correlative_amount'] = $correlative_amount;
            $regular['refund_amount'] = $refund_amount;
            $regular['withhold_amount'] = $withhold_amount;
            $data[] = $regular;
        }
        if ($boolSixteen_1) {
            $regularSixteen_1['loan_period'] = $loan_period;
            $data[] = $regularSixteen_1;
        }
        $refund = [
            [
                [
                    'loan_period' => '未放款-未退款订单',
                    'has_substitute_fee' => 0,
                    'order_quantity' => 0,
                    'transfer_amount' => 0,
                    'loan_amount' => 0,
                    'correlative_amount' => 0,
                    'refund_amount' => 0,
                    'withhold_amount' => 0,
                    'order_amount' => 0
                ],
                [
                    'loan_period' => '未放款-未退款订单',
                    'has_substitute_fee' => 1,
                    'order_quantity' => 0,
                    'transfer_amount' => 0,
                    'loan_amount' => 0,
                    'correlative_amount' => 0,
                    'refund_amount' => 0,
                    'withhold_amount' => 0,
                    'order_amount' => 0
                ]
            ],
            [
                [
                    'loan_period' => '未放款-已退款订单',
                    'has_substitute_fee' => 0,
                    'order_quantity' => 0,
                    'transfer_amount' => 0,
                    'loan_amount' => 0,
                    'correlative_amount' => 0,
                    'refund_amount' => 0,
                    'withhold_amount' => 0,
                    'order_amount' => 0
                ],
                [
                    'loan_period' => '未放款-已退款订单',
                    'has_substitute_fee' => 1,
                    'order_quantity' => 0,
                    'transfer_amount' => 0,
                    'loan_amount' => 0,
                    'correlative_amount' => 0,
                    'refund_amount' => 0,
                    'withhold_amount' => 0,
                    'order_amount' => 0,
                ],
            ],
            [
                [
                    'loan_period' => '退款订单',
                    'has_substitute_fee' => 0,
                    'order_quantity' => 0,
                    'transfer_amount' => 0,
                    'loan_amount' => 0,
                    'correlative_amount' => 0,
                    'refund_amount' => 0,
                    'withhold_amount' => 0,
                    'order_amount' => 0,
                ],
                [
                    'loan_period' => '退款订单',
                    'has_substitute_fee' => 1,
                    'order_quantity' => 0,
                    'transfer_amount' => 0,
                    'loan_amount' => 0,
                    'correlative_amount' => 0,
                    'refund_amount' => 0,
                    'withhold_amount' => 0,
                    'order_amount' => 0,
                ],
            ]
        ];
        foreach ($refundOrder as $k) {
            switch ($k['is_refund']) {
                case '00':
                    $this->mergeAliRefund($k, $refund[0], $refundTransfer);
                    break;
                case '01':
                    $this->mergeAliRefund($k, $refund[1], $refundTransfer);
                    break;
                case '11':
                    $this->mergeAliRefund($k, $refund[2], $refundTransfer);
                    break;
            }
        }
        foreach ($refund as $key => $value){
            foreach ($value as $vv){
                $data[] = $vv;
            }
        }
        return $data;
    }
    public function mergeAliRefund(&$data,&$refund,$refundTransfer)
    {
        foreach ($refundTransfer as $kk){
            if ($kk['is_refund'] == $data['is_refund']) {
                if (($kk['has_substitute_fee'] == $refund[0]['has_substitute_fee']) && ($refund[0]['has_substitute_fee'] == $data['has_substitute_fee'])) {
                    $refund[0]['order_quantity'] = $data['order_quantity'];
                    $refund[0]['order_amount'] = $data['order_amount'];
                    $refund[0]['transfer_amount'] = $kk['transfer_amount'];
                    $refund[0]['loan_amount'] = $kk['loan_amount'];
                    $refund[0]['refund_amount'] = $kk['refund_amount'];
                    $refund[0]['correlative_amount'] = $kk['correlative_amount'];
                    $refund[0]['withhold_amount'] = $kk['withhold_amount'];
                 }elseif(($kk['has_substitute_fee'] == $refund[1]['has_substitute_fee']) && ($refund[1]['has_substitute_fee'] == $data['has_substitute_fee'])){
                    $refund[1]['order_quantity'] = $data['order_quantity'];
                    $refund[1]['order_amount'] = $data['order_amount'];
                    $refund[1]['transfer_amount'] = $kk['transfer_amount'];
                    $refund[1]['loan_amount'] = $kk['loan_amount'];
                    $refund[1]['refund_amount'] = $kk['refund_amount'];
                    $refund[1]['correlative_amount'] = $kk['correlative_amount'];
                    $refund[1]['withhold_amount'] = $kk['withhold_amount'];
                }

            }
        }
    }
    public function mergeWishRefund(&$data,&$refund,$refundTransfer)
    {
        foreach ($refundTransfer as $kk){
            if ($kk['is_refund'] == $data['is_refund']) {
                $refund['order_quantity'] = $data['order_quantity'];
                $refund['order_amount'] = $data['order_amount'];
                $refund['transfer_amount'] = $kk['transfer_amount'];
                $refund['loan_amount'] = $kk['loan_amount'];
                $refund['refund_amount'] = $kk['refund_amount'];
                $refund['correlative_amount'] = $kk['correlative_amount'];
            }
        }
    }
    /**
     * 汇总代扣，非代扣，计算单元比例
     * @param $data
     * @return array
     */
    public function getAliTotalData(&$data)
    {
        /** 汇总 */
        $totalOrderQuantity = $totalOrderAmount = $totalLoanAmount = $totalTransferAmount = $totalCorrelativeAmount = $totalRefundAmount = $totalWithholdAmount = 0;
        foreach ($data as $item){
            $totalOrderQuantity += floatval(param($item,'order_quantity',0));
            $totalOrderAmount += floatval(param($item,'order_amount',0));
            $totalLoanAmount += floatval(param($item,'loan_amount',0));
            $totalRefundAmount += floatval(param($item,'refund_amount',0));
            $totalTransferAmount += floatval(param($item,'transfer_amount',0));
            $totalCorrelativeAmount += floatval(param($item,'correlative_amount',0));
            $totalWithholdAmount += floatval(param($item,'withhold_amount',0));
        }

        /** 计算单元比例 */
        foreach ($data as &$set){
            $set['real_loan_proportion'] = param($set,'order_amount',0) >0 ? (floatval($set['loan_amount']) / floatval($set['order_amount']))*100 : 0;
            $set['order_amount_proportion'] = $totalOrderAmount >0 ? (floatval($set['order_amount']) / $totalOrderAmount)*100 : 0;
            $set['order_quantity_proportion'] = $totalOrderQuantity >0 ? (floatval($set['order_quantity']) /$totalOrderQuantity)*100 : 0;
            $set['transfer_amount_proportion'] = param($set,'order_amount',0)  >0 ? (floatval($set['transfer_amount']) /floatval($set['order_amount']))*100 : 0;
            $set['refund_amount_proportion'] = param($set,'order_amount',0) >0 ? (floatval($set['refund_amount']) /floatval($set['order_amount']))*100 : 0;

        }
        $totalOrderAmountProportion = $totalOrderQuantityProportion = 0;
        $totalTotalLoanProportion = $totalOrderAmount > 0 ? ($totalLoanAmount / $totalOrderAmount) * 100 :0;
        $totalTotalRefundProportion = $totalOrderAmount > 0 ? ($totalRefundAmount / $totalOrderAmount) * 100 :0;
        $totalTransferAmountProportion = $totalOrderAmount >0 ? ($totalTransferAmount / $totalOrderAmount) * 100 : 0;
        foreach ($data as $s){
            $totalOrderAmountProportion += $s['order_amount_proportion'];
            $totalOrderQuantityProportion += $s['order_quantity_proportion'];
        }
        $total = [];

        /** 合并数组 */

        $total['loan_period'] = $data[0]['has_substitute_fee'] == 1 ? '线上运费订单汇总' : '线下运费订单汇总';
        $total['order_quantity'] = $totalOrderQuantity;
        $total['order_amount'] = $totalOrderAmount;
        $total['loan_amount'] = $totalLoanAmount;
        $total['refund_amount'] = $totalRefundAmount;
        $total['withhold_amount'] = $totalWithholdAmount;
        $total['transfer_amount'] = $totalTransferAmount;
        $total['correlative_amount'] = $totalCorrelativeAmount;
        $total['real_loan_proportion'] = $totalTotalLoanProportion;
        $total['refund_amount_proportion'] = $totalTotalRefundProportion;
        $total['order_amount_proportion'] = $totalOrderAmountProportion;
        $total['order_quantity_proportion'] = $totalOrderQuantityProportion;
        $total['transfer_amount_proportion'] = $totalTransferAmountProportion;
        $data[] = $total;
        return $total;
    }
    public function getExportData($params)
    {

        $res = [];
        $data = [];
        if (isset($params['channel_id']) && is_numeric($params['channel_id'])) {
            switch ($params['channel_id']){
                case 0:
                    return false;
                    break;
                case ChannelAccountConst::channel_aliExpress:
                    $where = $this->getSettleWhere($params);
                    $fields = [
                        'account_period_week loan_period',
                        'payment_amount',
                        'transfer_amount',
                        'currency_code',
                        'aliexpress_order_id order_id',
                        'account_id',
                        'payment_time',
                        'transfer_time',
                        'transfer_amount/payment_amount loan_proportion'
                    ];
                    $settleModel = new AliexpressSettlement();
                    $res = $settleModel->field($fields)
                        ->where($where)->where(function ($query){
                            $query->where('transfer_amount','<>','0')->whereOr('refund_amount','<=','0');
                        })->order('payment_time')->select();
                    $data = $this->formatExportAliData($res,$params);
                    break;
                case ChannelAccountConst::channel_wish:
                    $data = $this->getWishData($params);
                    break;
                default:
                    return false;
                    break;
            }
        }
        return $data;
    }

    public function formatExportAliData($res,$params)
    {
        $data = [];
        if (!$res) {
            return false;
        }
        foreach ($res as $key) {
            $datav = [];
            $datav['loan_period'] = $key['loan_period'];
            if (param($key,'pay_amount')) {
                $datav['pay_amount'] = param($key,'currency_code','').number_format($key['payment_amount'],2);
            }else {
                $datav['pay_amount'] = 0;
            }
            if (param($key,'transfer_amount_sum')) {
                $datav['loan_amount'] = param($key,'currency_code','').number_format($key['transfer_amount_sum'],2);
            }else {
                $datav['loan_amount'] = 0;
            }

            $datav['order_id'] = $key['order_id'];
            $datav['account_id'] = $key['account_id'];
            switch ($params['channel_id']){
                case 0:
                    if ($key['account_id'] != 0) {
                        $datav['account_code'] = '';
                    }
                    break;
                case ChannelAccountConst::channel_aliExpress:
                    if (param($key,'account_id',0) != 0) {
                        $accountInfo = Cache::store('AliexpressAccount')->getTableRecord($key['account_id']);
                        $datav['account_code'] = param($accountInfo,'code','');
                    }
                    break;
                case ChannelAccountConst::channel_wish:
                    if (param($key,'account_id',0) != 0) {
                        $accountInfo = Cache::store('WishAccount')->getAccount($key['account_id']);
                        $datav['account_code'] = param($accountInfo,'code','');
                    }
                    break;
                default:
                    return false;
                    break;
            }
            $datav['payment_time'] = param($key,'payment_time',0)>0 ? date('Y-m-d H:i:s',$key['payment_time']) : '--';
            $datav['loan_time'] = param($key,'transfer_time',0)>0 ? date('Y-m-d H:i:s',$key['transfer_time']) : '--';
            if (param($key,'transfer_time',0)>0 && param($key,'payment_time',0)>0) {
                $loanPeriodDay = ($key['transfer_time'] - $key['payment_time'])/86400;
                $datav['loan_period_day'] = number_format($loanPeriodDay,2);
            }else {
                $datav['loan_period_day'] = '--';
            }
            $datav['transfer_proportion'] = '--';
            $datav['transfer_amount'] = '--';
            $datav['loan_proportion'] = number_format($key['loan_proportion']*100,2).'%';
            $data[] = $datav;

        }
        return $data;
    }

    public function getExportFileName($params)
    {
        $fileName = '';
        $start_time = $params['date_b'] ?$params['date_b'] : '';
        $end_time = $params['date_e'] ?$params['date_e'] : '';
        if (isset($params['channel_id']) && is_numeric($params['channel_id'])) {
            switch ($params['channel_id']){
                case 0:
                    $fileName = '平台账期核算';
                    break;
                case ChannelAccountConst::channel_aliExpress:
                    $fileName = '速卖通平台帐期核算';
                    break;
                case ChannelAccountConst::channel_wish:
                    $fileName = 'wish平台帐期核算';
                    break;
                default:
                    $fileName = '平台账期核算';
                    break;
            }
        }
        if ($start_time && $end_time) {
            $fileName .= '('.date('Ymd',$start_time).'_'.date('Ymd',$end_time).')';
        }else {
            if ($start_time) {
                $fileName .= '('.date('Ymd',$start_time).')';
            }
            if ($end_time) {
                $fileName .= '('.date('Ymd',$end_time).')';
            }
        }
        $fileName .= '_'.date('ymd_his',time());
        return $fileName;
    }

    public function allExport($params)
    {
        try {
            set_time_limit(0);
            ini_set('memory_limit','1024M');
            $validate = new FileExportValidate();
            if (!$validate->scene('export')->check($params)) {
                throw new Exception($validate->getError());
            }
            $result = $this->export($params, $params['file_name'], 1);
            if(!$result['status']) throw new Exception($result['message']);
            if(is_file($result['file_path'])){
                $applyRecord = ReportExportFiles::get($params['apply_id']);
                $applyRecord->exported_time = time();
                $applyRecord->download_url = $result['download_url'];
                $applyRecord->status = 1;
                $applyRecord->isUpdate()->save();
            } else {
                throw new Exception('文件写入失败');
            }
        }catch (Exception $ex){
            $applyRecord = ReportExportFiles::get($params['apply_id']);
            $applyRecord->status = 2;
            $applyRecord->error_message = $ex->getMessage();
            $applyRecord->isUpdate()->save();
            throw new Exception($ex->getMessage());
        }
    }

    public  function export($params, $fileName = '', $isQueue = 0)
    {
            set_time_limit(0);
            $channel_id = param($params,'channel_id',0);
            $header = $this->getHeader($channel_id);
            $exportList = $this->getExportData($params);
            if (!$exportList) {
                return '无相关数据';
            }
            $file = [
                'name' => $fileName ? $fileName: '平台账期核算' .date("YmdHis"),
                'path' => 'settle_export'
            ];
            $result = Excel::exportExcel2007($header, $exportList , $file ,$isQueue);
            return $result;
    }
    public function formatDetailData($res,$channelId)
    {
        $datas = [];
        foreach ($res as $key) {
            $datav = [];
            $datav['loan_period'] = $key['loan_period'];
            $datav['order_amount'] = $key['payment_amount']>0?$key['currency_code'].number_format($key['payment_amount'],2):'--';
            $datav['loan_amount'] = $key['transfer_amount']>0?$key['currency_code'].number_format($key['transfer_amount'],2):'--';
            switch ($channelId) {
                case 0:
                    break;
                case ChannelAccountConst::channel_aliExpress:
                    $datav['order_id'] = $key['order_id'];
                    $datav['account_id'] = $key['account_id'];
                    if ($key['account_id'] != 0) {
                        $accountInfo = Cache::store('AliexpressAccount')->getTableRecord($key['account_id']);
                        $datav['account_code'] = $accountInfo['code'];
                    }
                    $datav['site'] = 'aliExpress/';
                    break;
                case ChannelAccountConst::channel_wish:
                    $datav['order_id'] = $key['order_id'];
                    $datav['account_id'] = $key['account_id'];
                    if ($key['account_id'] != 0) {
                        $accountInfo = Cache::store('WishAccount')->getAccount($key['account_id']);
                        $datav['account_code'] = $accountInfo['code'];
                    }
                    $datav['site'] = 'wish/';
                    break;
                default:
                    break;
            }
            $datav['payment_time'] = $key['payment_time'];
            $datav['loan_time'] = $key['transfer_time'];
            if ($key['transfer_time']>0 && $key['payment_time']>0) {
                $loanPeriodDay = ($key['transfer_time'] - $key['payment_time'])/86400;
                $datav['loan_period_day'] = number_format($loanPeriodDay,2);
            }else {
                $datav['loan_period_day'] = '--';
            }
            $datav['loan_proportion'] = number_format($key['loan_proportion']*100,2) .'%';
            $datav['transfer_amount'] = 0;
            $datav['transfer_proportion'] = 0* 100 . '%';
            $datas[] = $datav;
        }
        return $datas;
    }

    public function getHeader($channel_id)
    {
        $header = [];
        switch ($channel_id){
            case ChannelAccountConst::channel_aliExpress:
                $header = [
                    [ 'title'=>'订单号', 'key'=>'order_id', 'width'=>20 , 'need_merge' => 0],
                    [ 'title'=>'账号简称', 'key'=>'account_code', 'width'=>20 , 'need_merge' => 0],
                    [ 'title'=>'订单金额', 'key'=>'pay_amount', 'width'=>15, 'need_merge' => 0 ],
                    [ 'title'=>'付款时间', 'key'=>'payment_time', 'width'=>15, 'need_merge' => 0],
                    [ 'title'=>'放款金额', 'key'=>'loan_amount', 'width'=>15, 'need_merge' => 0 ],
                    [ 'title'=>'放款比例（订单额)', 'key'=>'loan_proportion', 'width'=>15, 'need_merge' => 0 ],
                    [ 'title'=>'转账金额', 'key'=>'transfer_amount', 'width'=>15, 'need_merge' => 0 ],
                    [ 'title'=>'转账比例', 'key'=>'transfer_proportion', 'width'=>15, 'need_merge' => 0 ],
                    [ 'title'=>'放款时间', 'key'=>'loan_time', 'width'=>15, 'need_merge' => 0],
                    [ 'title'=>'帐期（天）', 'key'=>'loan_period_day', 'width'=>15, 'need_merge' => 0],
                    [ 'title'=>'帐期（周）', 'key'=>'loan_period', 'width'=>15, 'need_merge' => 0],

                ];
                break;
            case ChannelAccountConst::channel_wish:
                $header = [
                    [ 'title'=>'放款帐期（周）', 'key'=>'loan_period', 'width'=>20 , 'need_merge' => 0],
                    [ 'title'=>'订单量', 'key'=>'order_quantity', 'width'=>15, 'need_merge' => 0 ],
                    [ 'title'=>'订单金额', 'key'=>'order_amount', 'width'=>15, 'need_merge' => 0],
                    [ 'title'=>'放款金额或退款金额', 'key'=>'loan_amount', 'width'=>22, 'need_merge' => 0 ],
                    [ 'title'=>'放款比例或退款比例', 'key'=>'real_loan_proportion', 'width'=>22, 'need_merge' => 0 ],
                    [ 'title'=>'订单相关费用金额合计', 'key'=>'correlative_amount', 'width'=>22, 'need_merge' => 0 ],
                    [ 'title'=>'转账金额', 'key'=>'transfer_amount', 'width'=>18, 'need_merge' => 0 ],
                    [ 'title'=>'转账金额占比', 'key'=>'transfer_amount_proportion', 'width'=>18, 'need_merge' => 0],
                    [ 'title'=>'账期占比(订单量)', 'key'=>'order_quantity_proportion', 'width'=>18, 'need_merge' => 0],
                    [ 'title'=>'账期占比(订单额)', 'key'=>'order_amount_proportion', 'width'=>18, 'need_merge' => 0],
                ];
                break;
        }
        return $header;
    }
}