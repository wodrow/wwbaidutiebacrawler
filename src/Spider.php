<?php
/**
 * Created by PhpStorm.
 * User: Wodro
 * Date: 2020/2/1
 * Time: 17:36
 */

namespace wodrow\wwbaidutiebacrawler;


use QL\QueryList;
use wodrow\yii2wtools\tools\ArrayHelper;
use wodrow\yii2wtools\tools\FileHelper;
use yii\base\Component;
use yii\base\Exception;
use yii\helpers\Html;

class Spider extends Component
{
    public $url; # data
    public $baiduTieziId;
    public $alias_upload_root = ""; // @uploads_root/baidu_tieba
    public $alias_upload_url = ""; // @uploads_url/baidu_tieba
    public $is_console = 0;
    public $is_cache = 0;
    public $title; # data
    public $post_ids; # data
    public $author_id; # data
    public $author_name; # data
    protected $upload_root;
    protected $upload_url;

    /**
     * @param $msg
     * @throws
     */
    public function error($msg)
    {
        if ($this->is_console){
            var_dump($msg);
            exit;
        }else{
            throw new Exception($msg);
        }
    }

    public function consoleMsg($msg)
    {
        if ($this->is_console)var_dump($msg);
    }

    /**
     * @throws
     */
    public function checkIsTieZi()
    {
        $_url = $this->url;
        $_url = str_replace('https', 'http', $_url);
        $str = "http://tieba.baidu.com/p/";
        $_id = str_replace($str, '', $_url);
        if (strpos($_id, '?')!==false){
            $arr = explode('?', $_id);
            $_id = $arr[0];
        }
        if (is_numeric($_id)) {
            $this->baiduTieziId = $_id;
            return $this->url = $str.$_id;
        } else {
            $this->error('不是帖子');
        }
    }

    public function getUploadRootAndUrl()
    {
        $_dir = str_replace("http://tieba.baidu.com/p/", "", $this->url);
        $this->upload_root = \Yii::getAlias("@uploads_root/baidu_tieba/{$_dir}");
        $this->upload_url = \Yii::getAlias("@uploads_url/baidu_tieba/{$_dir}");
        if (!is_dir($this->upload_root)){
            FileHelper::createDirectory($this->upload_root);
        }
    }

    public function getList()
    {
        $this->checkIsTieZi();
        $this->getUploadRootAndUrl();
        $ql = QueryList::getInstance()->get($this->url);
        $this->title = $ql->find(".core_title_txt")->attr('title');
        if ($this->title)$this->title .= " [引自百度贴吧]";
        $this->post_ids = [];
        $pages = $ql->find('.l_reply_num')->find('.red:eq(1)')->text();
        $this->consoleMsg($pages);
        if (!$this->is_cache)\Yii::$app->cache->delete('baiduTieziId_'.$this->baiduTieziId);
        $list = \Yii::$app->cache->get('baiduTieziId_'.$this->baiduTieziId);
        if (!$list){
            $list = [];
            for ($i = 1; $i <= $pages; $i++){
                $this->consoleMsg($i);
                if ($i == 1){
                    $_ql = $ql;
                }else{
                    $_ql = QueryList::getInstance()->get($this->url."?pn={$i}");
                }
                $_list = $_ql->rules([
//                    'html' => ['.j_l_post:visible .j_d_post_content', 'html'],
                    'html' => ['.j_l_post:visible', 'html'],
                    'text' => ['.j_l_post:visible', 'text'],
                    'tail' => ['.j_l_post:visible', 'data-field'],
                ])->queryData();
                $list = ArrayHelper::merge($list, $_list);
            }
            \Yii::$app->cache->set('baiduTieziId_'.$this->baiduTieziId, $list, 3600);
        }
        foreach ($list as $k => $v){
            $_ql = QueryList::getInstance()->html($v['html']);
            if (!$_ql->find('.j_d_post_content')->html()){
                unset($list[$k]);
            }else{
                $list[$k]['html'] = $_ql->find('.j_d_post_content')->html();
                $list[$k]['text'] = $_ql->find('.j_d_post_content')->text();
                $list[$k]['tail'] = json_decode($v['tail'], true);
            }
        }
        if (count($list) > 0){
            /*foreach ($list as $k => $v) {
                if ($this->is_console)var_dump("megre:".$k);
                if (isset($v['tail'])){
                    $tail = json_decode($list[$k]['tail'], true);
                    $fn = function (&$list, $k)use(&$fn, $tail){
                        if (!isset($list[$k]['html'])){
                            unset($list[$k]);
                            $k--;
                            $fn($list, $k);
                        }else{
                            $list[$k]['tail'] = $tail;
                        }
                    };
                    $fn($list, $k);
                }
            }*/
            foreach ($list as $k => $v) {
                $this->consoleMsg("list:".$k);
                $_ql = QueryList::getInstance()->html($v['html']);
                $images = $_ql->rules([
                    'image' => ['img', 'src'],
                ])->queryData();
                $videos = $_ql->rules([
                    'video' => ['embed', 'data-video'],
                ])->queryData();
                $imgs = $this->saveTieBaImage($images);
                $vids = $this->saveTieBaVideo($videos);
                $list[$k]['text'] = "<p>".implode('', $vids)."</p>"."<p>{$v['text']}</p>"."<p>".implode('', $imgs)."</p>";
            }
            foreach ($list as $k => $v){
                if (isset($v['tail'])) {
                    $tail = $list[$k]['tail'];
                    $this->post_ids[] = $tail['content']['post_id'];
                }
            }
            $this->author_id = $list[0]['tail']['author']['user_id'];
            $this->author_name = $list[0]['tail']['author']['user_name'];
            foreach ($list as $k => $v) {
                $list[$k]['post_id'] = $v['tail']['content']['post_id'];
                unset($list[$k]['tail']);
                unset($list[$k]['html']);
                $list[$k]['text'] .= " <p> [来自贴吧]</p>";
            }
        }
        return $list;
    }

    /**
     * @param $images
     * @return array
     * @throws
     */
    public function saveTieBaImage($images)
    {
        $imgs = [];
        foreach ($images as $k => $v){
            $image_name = basename($v['image']);
            if (strpos($image_name, '.png?t=') !== false){
                $_x = explode("?t=", $image_name);
                $image_name = $_x[0];
            }
            $root = $this->upload_root.DIRECTORY_SEPARATOR.$image_name;
            $url = $this->upload_url.DIRECTORY_SEPARATOR.$image_name;
            if (!file_exists($root)){
                $fg_con = @file_get_contents($v['image']);
                if ($fg_con){
                    file_put_contents($root, $fg_con);
                    $imgs[] = Html::img($url, ['class' => "img img-responsive"]);
                    $this->consoleMsg($url);
                }
            }
        }
        return $imgs;
    }

    /**
     * @param $videos
     * @return array
     * @throws
     */
    public function saveTieBaVideo($videos)
    {
        $vids = [];
        foreach ($videos as $k => $v){
            $video_name = basename($v['video']);
            if (strpos($video_name, '.png?t=') !== false){
                $_x = explode("?t=", $video_name);
                $video_name = $_x[0];
            }
            $root = $this->upload_root.DIRECTORY_SEPARATOR.$video_name;
            $url = $this->upload_url.DIRECTORY_SEPARATOR.$video_name;
            if (!file_exists($root)){
                $fg_con = @file_get_contents($v['image']);
                if ($fg_con){
                    file_put_contents($root, $fg_con);
                    $vids[] = "<video src='{$url}' controls='controls'>您的浏览器不支持 video 标签</video>";
                }
            }
//            $fi = new \finfo(FILEINFO_MIME_TYPE);
//            $mime_type = $fi->file($root);
        }
        return $vids;
    }
}