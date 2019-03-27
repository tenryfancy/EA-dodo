<?php
/**
 * Created by PhpStorm.
 * User: joy
 * Date: 18-1-7
 * Time: 上午9:58
 */

namespace app\common\model\joom;


use think\Exception;
use think\Model;
use think\Db;

class JoomTagSearch extends Model
{
    protected function initialize()
    {
        parent::initialize(); // TODO: Change the autogenerated stub
    }

    public function updateTags($result) {
        if(empty($result['tags'])) {
            return true;
        }
        $time = time();
        try {
            foreach($result['tags'] as $tag) {
                $count = $this->where(['keyword' => $result['keyword'], 'tag' => $tag])->count();
                if($count) continue;
                $this->insert(['keyword' => $result['keyword'], 'tag' => $tag, 'create_time' => $time]);
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }

        return true;
    }
}