<?php
/**
 * Created by PhpStorm.
 * User: wuchuguang
 * Date: 17-8-3
 * Time: 下午1:37
 */

namespace app\index\queue;


use app\common\service\SwooleQueueJob;

class WarehouseQueue2 extends SwooleQueueJob
{
    public function getName(): string
    {
        return "队列daemo";
    }

    public function getDesc(): string
    {
        return "队列daemo";
    }

    public function getAuthor(): string
    {
        return "WCG";
    }

    public function execute()
    {
        echo "warehouseQueue\n";
        dump_detail($this->params);
    }
}