<?php
// +----------------------------------------------------------------------
// | 
// +----------------------------------------------------------------------
// | File  : ProfitExportQueue.php
// +----------------------------------------------------------------------
// | Author: LiuLianSen <3024046831@qq.com>
// +----------------------------------------------------------------------
// | Date  : 2017-08-07
// +----------------------------------------------------------------------

namespace  app\report\queue;

use app\common\cache\Cache;
use app\common\service\SwooleQueueJob;
use app\report\service\ProfitStatement;
use app\report\service\StatisticDeeps;


class CheckPackageDeeps extends SwooleQueueJob
{
    public function getName(): string
    {
        return "查询已发货的包裹";
    }

    public function getDesc(): string
    {
        return "查询已发货的包裹";
    }

    public function getAuthor(): string
    {
        return "Phill";
    }

    public static function swooleTaskMaxNumber():int
    {
        return 10;
    }

    public function execute()
    {
        $data = $this->params;
        try {
            $deepsService = new StatisticDeeps();
            $deepsService->writeBackPackage($data['begin_time'],$data['end_time']);
        }catch (\Exception $ex){

        }
    }
}