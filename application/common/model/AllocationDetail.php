<?php
namespace app\common\model;

use think\Model;

/**
 * Created by tanbin.
 * User: XPDN
 * Date: 2017/06/20
 * Time: 9:13
 */
class AllocationDetail extends Model
{

    /**
     * 初始化
     */
    protected function initialize()
    {
        parent::initialize();
    }

    /** 检测是否存在
     * @param int $id
     * @return bool
     */
    public function isHas($id = 0)
    {
        $result = $this->where(['id' => $id])->find();
        if(empty($result)){   //不存在
            return false;
        }
        return true;
    }
}