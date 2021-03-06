<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 2018/7/12
 * Time: 21:26
 */

namespace app\index\service;

use app\common\model\Account;
use app\common\model\ebay\EbayAccount;
use app\common\model\ebay\EbayAccountHealth;
use app\common\model\ebay\EbayAccountHealthSet;
use app\common\model\User as UserModel;
use app\common\service\CommonQueuer;
use app\common\service\Encryption;
use app\common\cache\Cache;
use app\index\queue\EbayAccountHealthExportQueue;
use app\report\model\ReportExportFiles;
use erp\ErpRbac;
use think\Exception;
use app\common\traits\User;

class EbayAccountHealthService
{
    use User;
    private $userId;
    public function __construct(int $userId = 0)
    {
        $this->userId = $userId;
    }
    /**
     * 打包要发送给爬虫服务器的数据
     * @param int $accountId
     * @throws Exception
     */
    public function packSendData(int $accountId)
    {
        try {
            $accountName = EbayAccount::where(['id'=>$accountId])->value('account_name');
            $accountInfo = (new Account())->alias('a')->field('a.account_name,a.account_code,a.password,s.ip')
                ->join('server s', 'a.server_id=s.id', 'LEFT')->where(['a.channel_id'=>1, 'a.account_name'=>$accountName])
                ->find();
            //检查数据有效性
            if (empty($accountInfo) || empty($accountInfo['account_name'])
                || empty($accountInfo['account_code']) || empty($accountInfo['ip'])) {
                throw new Exception('账号信息获取失败');
            }
            $postData['Ebay'][0]['account'] = $accountInfo['account_name'];
            $postData['Ebay'][0]['abbreviation'] = $accountInfo['account_code'];
            $postData['Ebay'][0]['password'] = (new Encryption())->decrypt($accountInfo['password']);
            $postData['Ebay'][0]['site'] = '';
            $postData['Ebay'] = json_encode($postData['Ebay']);
            $postData['CallbackUrl'] ='http://www.zrzsoft.com:8081/api/health-receive/ebay/';
            $ip = $accountInfo['ip'];
            return ['data'=>$postData, 'ip'=>$ip];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 向爬虫服务器发送请求
     * @param int $id
     * @throws Exception
     */
    public function sendRequest(int $id)
    {
        try {
            $accountInfo = $this->packSendData($id);
            $url = 'http://'.$accountInfo['ip'].':10088/start_reptile/Ebay';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($accountInfo['data']));
            curl_setopt($ch, CURLINFO_HEADER_OUT, true);
            curl_setopt($ch, CURLOPT_URL, $url);
            $response = curl_exec($ch);
            if (false === $response) {
                $http_info = curl_getinfo($ch);
                throw new Exception(json_encode(['error' => curl_error($ch), 'debugInfo' => $http_info]));
            }
            curl_close($ch);
            $res = json_decode($response, true);
            if ($res['status'] == 'Fail') {
                throw new Exception(isset($res['message']) ? $res['message'] : '未知错误');
            }
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 保存健康数据
     * @param array $data
     * @throws Exception
     */
    public function saveHealthData(array $data)
    {
        try {
            $healths = json_decode($data['HealthData'], true);
            if (empty($healths)) return;
            $insertData = [];
            $accountId = EbayAccount::where(['account_name'=>$data['account']])->value('id');
            foreach ($healths as $k => $health) {
                $insertData[$k]['account_id'] = $accountId;
                //返回的格式为 Region: USA
                $insertData[$k]['region'] = trim(str_replace('Region:', '', $health['Region']));
                if (!empty($health['CurrentSellerLevel'])) {
                    $currencyLevel = $health['CurrentSellerLevel'];
                    $insertData[$k]['current_seller_level'] = isset($currencyLevel['level']) ? $currencyLevel['level'] : '';
                    $insertData[$k]['c_evaluated_date'] = empty($currencyLevel['evaluated_date']) ? '' : $currencyLevel['evaluated_date'];
                    $insertData[$k]['c_transaction_defect_rate'] = empty($currencyLevel['transaction_defect_rate']) ? 0.00 :
                        str_replace('%', '', $currencyLevel['transaction_defect_rate']);
                    $insertData[$k]['c_late_shipment_rate'] = empty($currencyLevel['late_shipment_rate']) ? 0.00 :
                        str_replace('%', '', $currencyLevel['late_shipment_rate']);
                    $insertData[$k]['c_case_closed_noresolve'] = empty($currencyLevel['case_closed_noresolve']) ? 0.00 :
                        str_replace('%', '', $currencyLevel['case_closed_noresolve']);
                    $insertData[$k]['c_validated_tracking_upload'] = empty($currencyLevel['validated_tracking_upload']) ? 0.00 :
                        str_replace('%', '', $currencyLevel['validated_tracking_upload']);
                    $insertData[$k]['c_transactions'] = empty($currencyLevel['transactions']) ? 0 :
                        str_replace(',', '', $currencyLevel['transactions']);
                    if (!empty($currencyLevel['sales'])) {
                        $cSalesCurrency = $this->paresSales($currencyLevel['sales']);
                        $insertData[$k]['c_sales'] = $cSalesCurrency['sales'];
                    } else {
                        $insertData[$k]['c_sales'] = 0.00;
                    }
                }
                if (!empty($health['TodayLevel'])) {
                    $todayLevel = $health['TodayLevel'];
                    $insertData[$k]['today_level'] = empty($todayLevel['level']) ? '' : $todayLevel['level'];
                    $insertData[$k]['next_evaluated_date'] = empty($todayLevel['next_evaluated_date']) ? '' : $todayLevel['next_evaluated_date'];
                    $insertData[$k]['t_transaction_defect_rate'] = empty($todayLevel['transaction_defect_rate']) ? 0.00 :
                        str_replace('%', '', $todayLevel['transaction_defect_rate']);
                    $insertData[$k]['t_late_shipment_rate'] = empty($todayLevel['late_shipment_rate']) ? 0.00 :
                        str_replace('%', '', $todayLevel['late_shipment_rate']);
                    $insertData[$k]['t_case_closed_noresolve'] = empty($todayLevel['case_closed_noresolve']) ? 0.00 :
                        str_replace('%', '', $todayLevel['case_closed_noresolve']);
                    $insertData[$k]['t_validated_tracking_upload'] = empty($todayLevel['validated_tracking_upload']) ? 0.00 :
                        str_replace('%', '', $todayLevel['validated_tracking_upload']);
                    $insertData[$k]['t_transactions'] = empty($todayLevel['transactions']) ? 0 :
                        str_replace(',', '', $todayLevel['transactions']);
                    if (!empty($todayLevel['sales'])) {
                        $tSalesCurrency = $this->paresSales($todayLevel['sales']);
                        $insertData[$k]['t_sales'] = $tSalesCurrency['sales'];
                    } else {
                        $insertData[$k]['t_sales'] = 0.00;
                    }
                    $insertData[$k]['return_rate'] = empty($health['return_rate']) ? 0.00 : str_replace('%', '', $health['return_rate']);
                    $insertData[$k]['total_transactions'] = empty($health['total_transactions']) ? 0.00 : str_replace(',', '', $health['total_transactions']);

                    $totalSalesCurrency = $this->paresSales($health['total_sales']);
                    $insertData[$k]['total_sales'] = $totalSalesCurrency['sales'];
                    $insertData[$k]['currency'] = $totalSalesCurrency['currency'];

                    $insertData[$k]['is_latest'] = 1;
                    $insertData[$k]['create_time'] = time();
                }
            }
            EbayAccountHealth::update(['is_latest'=>0], ['account_id'=>$accountId, 'is_latest'=>1]);//之前的最新变为旧的
            (new EbayAccountHealth())->saveAll($insertData);
            Cache::store('EbayAccount')->ebayLastUpdateTime($accountId,'health',['last_update_time'=>time()]);
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     *从带有非数字字符的字符串中解析出里面的数字，包括小数点和货币单位
     * @param string $salesStr
     * @return array
     * @throws Exception
     */
    public function paresSales(string $salesStr) : array
    {
        try {
            $salesAr = [];
            $currencyAr = [];
            $len = strlen($salesStr);
            for ($i=0; $i<$len; $i++) {
                if (is_numeric($salesStr[$i]) || $salesStr[$i] == '.') {
                    $salesAr[] = $salesStr[$i];
                } else if ($salesStr[$i] != ' ' && $salesStr[$i] != ',') {
                    $currencyAr[] = $salesStr[$i];
                }
            }
            $sales = implode('', $salesAr);
            $currency = implode('', $currencyAr);
            return ['sales'=>$sales, 'currency'=>$currency];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取账号健康数据列表
     * @param array $params
     * @param int $type 0,常规搜索模式；1,导出计算总数量；2,导出获取数据
     * @return mixed
     * @throws Exception
     */
    public function getLists(array $params, $type=0)
    {
        try {
            $wh = [];
            $page = empty($params['page']) ? 1 : $params['page'];
            $size = empty($params['size']) ? 50 : $params['size'];
            !empty($params['account_id']) && $wh['h.account_id'] = $params['account_id'];
            !empty($params['region']) && $wh['h.region'] = ['=',$params['region']];
            !empty($params['site_name']) && $wh['h.region'] = ['like', '%'.$params['site_name'].'%'];
            if (!empty($params['order'])) {
                if (strpos($params['order'],'create_time') !== false) {
                    $order = 'h.'.trim($params['order']);
                }
                if (strpos($params['order'],'code') !== false) {
                    $order = 'a.'.trim($params['order']);
                }
            }
            if (!empty($params['start_time']) || !empty($params['end_time'])) {
                $startTime = empty($params['start_time']) ? 0 : strtotime($params['start_time']);
                $endTime = empty($params['end_time']) ? time() : strtotime($params['end_time']);
                $wh['h.create_time'] = ['between', [$startTime, $endTime]];
            } else {
                !empty($params['latest']) && $wh['h.is_latest'] = $params['latest'];
            }
            if ($type == 1) {
                $count = (new EbayAccountHealth())->alias('h')->where($wh)->count();
                return $count;
            }

            $field = 'h.*,a.account_name,a.code,
                s.c_transaction_defect_rate as set_c_transaction_defect_rate,
                s.c_late_shipment_rate as set_c_late_shipment_rate,
                s.c_case_closed_noresolve as set_c_case_closed_noresolve,
                s.c_validated_tracking_upload as set_c_validated_tracking_upload,
                s.c_transactions as set_c_transactions,
                s.c_sales as set_c_sales,
                s.t_transaction_defect_rate as set_t_transaction_defect_rate,
                s.t_late_shipment_rate as set_t_late_shipment_rate,
                s.t_case_closed_noresolve as set_t_case_closed_noresolve,
                s.t_validated_tracking_upload as set_t_validated_tracking_upload,
                s.t_transactions as set_t_transactions,
                s.t_sales as set_t_sales,
                s.return_rate as set_return_rate,
                s.total_transactions as set_total_transactions,
                s.total_sales as set_total_sales';
            $lists = (new EbayAccountHealth())->alias('h')->field($field)
                ->join('ebay_account a', 'h.account_id=a.id', 'LEFT')
                ->join('ebay_account_health_set s', 's.account_id=h.account_id and s.region=h.region','LEFT')
                ->where($wh);
            if (empty($params['latest'])) {//查历史数据时，按id降序排列
                $lists = $lists->order('h.id desc');
            } else {//最新数据，按账号简称字母顺序排列
                $lists = $lists->order(empty($order) ? 'a.code' : $order);
            }
            $lists = $lists->page($page,$size)->select();
            if ($lists) {
                $lists = collection($lists)->toArray();
                $accountIds = array_column($lists,'account_id');
                $accountIds = array_values(array_unique($accountIds));
                $accountSet = EbayAccount::whereIn('id',$accountIds)->column('health_monitor,is_invalid','id');
                foreach ($lists as &$list) {
                    $tmpSet = $accountSet[$list['account_id']] ?? '';
                    if ($tmpSet) {//有对应值
                        $tmpInterval = $tmpSet['health_monitor'];
                        $interval = 0;
                        if ($tmpInterval) {//有值
                            if ($tmpInterval < 60) {
                                $interval = $tmpInterval.'分钟';
                            } else {
                                $interval = $tmpInterval/60 .'小时';
                            }
                        }
                        $list['sync_interval'] = $interval ?: '未启用';
                        $list['is_invalid'] = $tmpSet['is_invalid'] ? '启用' : '未启用';
                    } else {
                        $list['sync_interval'] = '未知';
                        $list['is_invalid'] = '未知';
                    }
                }
            }
            if ($type == 2) {
                return $lists;
            }
            $count = (new EbayAccountHealth())->alias('h')->where($wh)->count();
            return ['lists'=>$lists, 'count'=>$count];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 获取账号设置信息
     * @param int $accountId 账号id
     * @param string $reion 区域
     * @return EbayAccountHealthSet|array
     * @throws Exception
     */
    public function getAccountHealthSetting(int $accountId, string $region)
    {
        try {
            if (empty($accountId) || empty($region)) {
                throw new Exception('账号id和区域必须传递');
            }
            $wh['account_id'] = $accountId;
            $wh['region'] = $region;
            $row = EbayAccountHealthSet::get($wh);
            return empty($row) ? [] : $row;
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 设置监测阈值，可批量
     * @param array $params
     * @throws Exception
     */
    public function setAccountHealthSetting(array $params)
    {
        try {
            $accountRegions = json_decode($params['account_region'], true);//账号区域对
            $setting = json_decode($params['setting'], true);//设置详情
            if (empty($accountRegions) || empty($setting)) {
                throw new Exception('传递的数据有误，请检查');
            }
            $setting['updater_id'] = $this->userId;
            $setting['update_time'] = time();
            foreach ($accountRegions as $k => $accountRegion) {
                $accountId = $accountRegion['account_id'];
                $region = $accountRegion['region'];
                $oldInfo = EbayAccountHealthSet::get(['account_id'=>$accountId, 'region'=>$region]);//先查询
                $setting['account_id'] = $accountId;
                $setting['region'] = $region;
                if (empty($oldInfo)) {//新增
                    $setting['creator_id'] = $this->userId;
                    $setting['create_time'] = time();
                    EbayAccountHealthSet::create($setting);
                } else {//更新
                    EbayAccountHealthSet::update($setting, ['id'=>$oldInfo->id]);
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }


    /**
     * 立即执行一次抓取
     * @throws Exception
     */
    public function syncImmediately(array $accountIds)
    {
        try {
            $count = 0;
            $codes = EbayAccount::whereIn('id',$accountIds)->column('code','id');
            $failCode = [];
            $accountIds = array_unique($accountIds);
            foreach ($accountIds as $k => $accountId) {
                try {
                    $this->sendRequest($accountId);
                    $count++;
                } catch (\Exception $e) {
                    $failCode[] = $codes[$accountId];
                }
            }
            $message = '成功发送'.$count.'条请求，'.(empty($failCode)?'':'其中'.implode(',',array_unique($failCode)).'发送失败，');
            $message .= '发送成功的，数据不会立即返回，请稍后刷新查询';
            return ['result'=>true,'message'=>$message];
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    /**
     * 导出健康数据
     * @param $ids
     * @param int $type 导出类型。0，正常导出；1，队列导出
     * @return array
     * @throws Exception
     */
    public function export($params,$type=0)
    {
        try {
            $header = [
                ['title' => '账号简称', 'width'=>20, 'key'=>'code'],
                ['title' => '区域', 'width'=>20, 'key'=>'region'],
                ['title' => 'Current seller level', 'width'=>20, 'key'=>'current_seller_level'],
                ['title' => 'Transaction defect rate', 'width'=>20, 'key'=>'c_transaction_defect_rate'],
                ['title' => 'Late shipment rate', 'width'=>20, 'key'=>'c_late_shipment_rate'],
                ['title' => 'Cases closed without seller resolution', 'width'=>20, 'key'=>'c_case_closed_noresolve'],
                ['title' => 'If we evaluated you today', 'width'=>20, 'key'=>'today_level'],
                ['title' => 'Transaction defect rate', 'width'=>20, 'key'=>'t_transaction_defect_rate'],
                ['title' => 'Late shipment rate', 'width'=>20, 'key'=>'t_late_shipment_rate'],
                ['title' => 'Case closed without seller resolution', 'width'=>20, 'key'=>'t_case_closed_noresolve'],
                ['title' => 'Return rate', 'width'=>20, 'key'=>'return_rate'],
                ['title' => '同步健康数据', 'width'=>20, 'key'=>'sync_interval'],
                ['title' => '系统状态', 'width'=>20, 'key'=>'is_invalid'],
                ['title' => '抓取时间', 'width'=>20, 'key'=>'create_time']
            ];
            //判断请求是否过于频繁
            if (!$type) {
                $lastRequestTime = Cache::handler()->get('ebay_health_export_time_'.$this->userId);
                if ($lastRequestTime && time()-$lastRequestTime < 5) {
                    throw new Exception('请求过于频繁');
                } else {
                    Cache::handler()->set('ebay_health_export_time_'.$this->userId,time(),10);//10s过期
                }
            }
            $count = $this->getLists($params,1);
            if (!$type && $count>500) {//正常导出时，如果总数量大于500条，走队列
                $model = new ReportExportFiles();
                $data['applicant_id'] = $this->userId;
                $data['apply_time'] = time();
                $data['export_file_name'] = $this->createExportFileName($params);
                $data['status'] = 0;
                $data['applicant_id'] = $this->userId;
                $model->allowField(true)->isUpdate(false)->save($data);
                $params['file_name'] = $data['export_file_name'];
                $params['apply_id'] = $model->id;
                (new CommonQueuer(EbayAccountHealthExportQueue::class))->push($params);
                $message = '导出任务添加成功，请到报表导出管理处下载EXCEL';
                return ['message'=>$message];
            }

            $lists = [];
            if (!$type) {//正常导出，数据小于500条，直接获取全部数据
                $params['size'] = $count;//重新设置每页条目数
                $lists = $this->getLists($params,2);
                $params['file_name'] = $this->createExportFileName($params);
            } else {//队列导出
                set_time_limit(0);
//                ini_set('memory_limit','4096M');
                //分批导出
                $pageSize = 500;
                $loop = ceil($count/$pageSize);
                for ($i=0;$i<$loop;$i++) {
                    $params['page'] = $i+1;
                    $params['size'] = $pageSize;
                    $tmpList = $this->getLists($params,2);
                    $tmpList =  $tmpList ?: [];
                    $lists = array_merge($lists,$tmpList);
                }
            }
            $file = [
                'name' => $params['file_name'],
                'title' => 'eBay健康数据',
                'path' => 'ebay_account_health'
            ];
            $res = DownloadFileService::export($lists, $header, $file);
            if ($type) {//队列导出
                $applyRecord = ReportExportFiles::get($params['apply_id']);
                if (!$applyRecord) {
                    throw new Exception('报表记录获取失败，无法将生成的文件信息写入');
                }
                if ($res['message'] != 'OK') {//导出失败
                    $applyRecord['status'] = 2;
                    $applyRecord['error_message'] = $res['message'];
                    $applyRecord->isUpdate(true)->save();
                } else {//导出成功
                    $applyRecord['exported_time'] = time();
                    $applyRecord['download_url'] = ROOT_PATH . 'public' . DS . 'download' . DS . $file['path'].DS.$file['name'].'xlsx';
                    $applyRecord['status'] = 1;
                    $applyRecord->allowField(true)->isUpdate(true)->save();
                }
            } else {//正常导出
                if ($res['message'] != 'OK') {//导出失败
                    throw new Exception($res['message']);
                } else {//导出成功
                    return $res;
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

    public function createExportFileName($params)
    {
        $fileName = '账号健康数据';
        if ($params['account_id']??0) {
            $accountCode = EbayAccount::where('id',$params['account_id'])->value('code');
            $fileName .= '_'.$accountCode;
        }
        if ($params['region']??'') {
            $fileName .= '_'.$params['region'];
        }
        if ($params['start_time']??0) {
            $fileName .= '_'.$params['start_time'];
        }
        if ($params['end_time']??0) {
            $fileName .= '_'.$params['end_time'];
        }
        return $fileName;
    }

    /**
     * 获取有权限的账号
     * @param $params
     * @throws Exception
     */
    public function getEbayHealthAccount($params)
    {
        try {
            $serverIp = gethostbyname($_SERVER['SERVER_NAME']);
            $nodeFlag = strpos($serverIp,'172.18.8.241')!==false || strpos($serverIp,'172.19.23')!==false;
            $nodeId = $nodeFlag ? 338037 : 338166;//节点id,与获取列表节点保持一致
            //解析条件
            $query = $params['query'];
            $page = (!isset($params['page']) || !is_numeric($params['page'])) ? 1 : $params['page'];
            $pageSize = (!isset($params['pageSize']) || !is_numeric($params['pageSize'])) ? 50 : $params['pageSize'];

            $admin = (new Role())->isAdmin($this->userId) ||
                UserModel::where('id',$this->userId)->value('job') == 'IT';
            if (!$admin) {//不是管理员或IT人员
                $underlineUserIds = $this->getUnderlingInfo($this->userId);
                $wh['c.seller_id'] = ['in',$underlineUserIds];
            }
            if ($query) {//有查询条件
                $wh['a.code'] = ['like',$query.'%'];
            }
            //查过滤器
            $role = ErpRbac::getRbac($this->userId);
            $filters = $role->getFilters($nodeId);
            if ($filters) {//过滤器存在且有设置
                foreach ($filters as $name => $filter) {
                    if ($name != 'app\\index\\filter\\EbayAccountHealthFilter') {
                        continue;
                    }
                    if ($filter == '') {//过滤器关闭了，带出所有的账号
                        unset($wh['c.seller_id']);
                    } else {//获取过滤器设置
                        if (!is_array($filter)) {
                            continue;
                        }
                        if (count($filter) == 1 && $filter[0] == 0) {
                            //看自己不做处理
                            continue;
                        } else {
                            if (($key = array_search(0,$filter)) !== false) {//有设置看自己
                                unset($filter[$key]);
                                $whOr['c.account_id'] = ['in',$filter];
                            } else {//如果没有设置看自己，则仅看设置的账号
                                $wh['c.account_id'] = ['in',$filter];
                                unset($wh['c.seller_id']);//不能绑定人员
                            }
                        }
                    }
                }
            }

            $wh['u.status'] = 1;
            $wh['u.job'] = 'sales';
            $wh['a.is_invalid'] = 1;
            $wh['a.account_status'] = 1;
            $wh['c.channel_id'] = 1;
            $field = 'a.id,a.account_name,a.code';

            if (isset($whOr)) {
                $accounts = \app\common\model\ChannelUserAccountMap::alias('c')
                    ->where($wh)->whereOr($whOr)->field($field)
                    ->join('user u','u.id=c.seller_id','LEFT')
                    ->join('ebay_account a','a.id=c.account_id','LEFT')
                    ->group('c.account_id')->order('a.code')->page($page,$pageSize)->select();
                $count = \app\common\model\ChannelUserAccountMap::alias('c')
                    ->where($wh)->whereOr($whOr)->field($field)
                    ->join('user u','u.id=c.seller_id','LEFT')
                    ->join('ebay_account a','a.id=c.account_id','LEFT')
                    ->group('c.account_id')->count();
            } else {
                $accounts = \app\common\model\ChannelUserAccountMap::alias('c')->where($wh)
                    ->field($field)->join('user u','u.id=c.seller_id','LEFT')
                    ->join('ebay_account a','a.id=c.account_id','LEFT')
                    ->order('a.code')->group('c.account_id')->page($page,$pageSize)->select();
                $count = \app\common\model\ChannelUserAccountMap::alias('c')->where($wh)
                    ->field($field)->join('user u','u.id=c.seller_id','LEFT')
                    ->join('ebay_account a','a.id=c.account_id','LEFT')
                    ->group('c.account_id')->count();
            }
            return [
                'data' => $accounts ? $accounts : [],
                'count' => $count,
                'page' => $page,
                'pageSize' => $pageSize
            ];
        } catch (\Exception $e) {
            throw new Exception($e->getFile().'|'.$e->getLine().'|'.$e->getMessage());
        }
    }

}
