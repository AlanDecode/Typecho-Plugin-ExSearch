<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit;?>

<?php
/**
 * Typecho 前端搜索插件
 * 
 * 本插件提供搜索实时响应、高亮功能。
 * 
 * @package ExSearch
 * @author 熊猫小A
 * @version 0.1
 * @link https://www.imalan.cn
 */

class ExSearch_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // hook for header and footer
        Typecho_Plugin::factory('Widget_Archive')->header = array('ExSearch_Plugin', 'header');
        Typecho_Plugin::factory('Widget_Archive')->footer = array('ExSearch_Plugin', 'footer');

        // 添加路由
        Helper::addRoute("route_ExSearch","/ExSearch","ExSearch_Action",'action');

        $db= Typecho_Db::get();
        
        $sql = 'select version();';
        $row = $db->fetchRow($db->select('version();'));
        $ver = $row['version()'];
        $charset = $ver >= '5.5.3' ? 'utf8mb4' : 'utf8';

        // 创建表
        $dbname =$db->getPrefix() . 'exsearch';
        $sql = "SHOW TABLES LIKE '%" . $dbname . "%'";
        if (count($db->fetchAll($sql)) == 0) {
            $sql = '
            DROP TABLE IF EXISTS `'.$dbname.'`;
            create table `'.$dbname.'` (
                `id` int unsigned auto_increment,
                `key` char(32) not null,
                `data` longtext,
                primary key (`id`)
            ) default charset='.$charset;
 
            $sqls = explode(';', $sql);
            foreach ($sqls as $sql) {
                $db->query($sql);
            }
        } else {
            $db->query($db->delete('table.exsearch')->where('id >= ?', 0));
        }

        // 注册文章、页面保存时的 hook（JSON 写入数据库）
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('ExSearch_Plugin', 'save');
        Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('ExSearch_Plugin', 'save');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        // 删除路由
        Helper::removeRoute("route_ExSearch");
        
        // Drop 表
        $db= Typecho_Db::get();
        $dbname =$db->getPrefix() . 'exsearch';
        $sql = 'DROP TABLE IF EXISTS `'.$dbname.'`';
        $db->query($sql);
    }

    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form){
?>
<p>
    <h3>使用方式</h3>
    &lt;button class=&quot;search-form-input&quot;&gt;搜索&lt;/button&gt;
</p>
<p>启用插件后请保存一次设置。保存插件设置后请<a href="<?php Helper::options()->index('/ExSearch?action=rebuild'); ?>" target="_blank">重建索引</a>。重建索引会清除所有数据库缓存，静态化缓存不会被清除。</p>
<?php
        // JSON 静态化
        $t = new Typecho_Widget_Helper_Form_Element_Radio(
            'static',
            array('true' => '是','false' => '否'),
            'false',
            '静态化',
            '静态化可以节省数据库调用，降低服务器压力。<mark>若需启用，需要保证本插件目录中 cache 文件夹可写。</mark>'
        );
        $form->addInput($t);
    }

    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 更新数据库
     * 
     * @access public
     * @return void
     */
    public static function save()
    {
        $db = Typecho_Db::get();

        // 防止过大的内容导致 MySQL 报错
        $sql = 'SET GLOBAL max_allowed_packet=4294967295;';
        $db->query($sql);

        // 获取搜索范围配置，query 对应内容
        $cache = array();
        $cache['posts'] = self::build('post');
        $cache['pages'] = self::build('page');

        $cache = json_encode($cache);
        $md5 = md5($cache);

        if(Helper::options()->plugin('ExSearch')->static == 'true')
        {
            $code = file_put_contents(__DIR__.'/cache/cache-'.$md5.'.json', $cache);
            if($code < 1)
            {
                throw new Typecho_Plugin_Exception('ExSearch 索引写入失败，请保证缓存目录可写', 1);
                exit(1);
            }
            $db->query($db->insert('table.exsearch')->rows(array(
                'key' => $md5,
                'data' => ''
            )));
        }
        else
        {
            $db->query($db->insert('table.exsearch')->rows(array(
                'key' => $md5,
                'data' => $cache
            )));
        }
    }  

    /**
     * 生成对象
     * 
     * @access private
     * @return array
     */
    private static function build($type)
    {
        $db = Typecho_Db::get();
        $rows = $db->fetchAll($db->select()->from('table.contents')
                ->where('table.contents.type = ?', $type)
                ->where('table.contents.status = ?', 'publish')
                ->where('table.contents.password IS NULL'));
        $cache = array();
        foreach ($rows as $row) {
            $widget = self::widget('Contents', $row['cid']);
            $item = array(
                'title' => $row['title'],
                'date' => date('c', $row['created']),
                'path' => $widget->permalink,
                'text' => strip_tags($widget->content)
            );

            if($type == 'post')
            {
                // 分类与标签
                $tags = array();
                $cates = array();
                $mids = $db->fetchAll($db->select()->from('table.relationships')
                        ->where('table.relationships.cid = ?', $row['cid']));

                foreach ($mids as $mid) {
                    $mid = $mid['mid'];
                    $meta = self::widget('Metas', $mid);
                    if($meta->type == 'category')
                    {
                        $cates[] = array(
                            'name' => $meta->name,
                            'slug' => $meta->slug,
                            'permalink' => $meta->permalink
                        );
                    }
                    if($meta->type == 'tag')
                    {
                        $tags[] = array(
                            'name' => $meta->name,
                            'slug' => $meta->slug,
                            'permalink' => $meta->permalink
                        );
                    }
                }
                $item['tags'] = $tags;
                $item['categories'] = $cates;
            }

            $cache[]=$item;
        }
        return $cache;
    }

    /**
     * 根据 cid 生成对象
     * 
     * @access private
     * @param string $table 表名, 支持 contents, comments, metas, users
     * @return Widget_Abstract
     */
    private static function widget($table, $pkId)
    {
        $table = ucfirst($table);
        if (!in_array($table, array('Contents', 'Comments', 'Metas', 'Users'))) {
            return NULL;
        }
        $keys = array(
            'Contents'  =>  'cid',
            'Comments'  =>  'coid',
            'Metas'     =>  'mid',
            'Users'     =>  'uid'
        );
        $className = "Widget_Abstract_{$table}";
        $key = $keys[$table];
        $db = Typecho_Db::get();
        $widget = new $className(Typecho_Request::getInstance(), Typecho_Widget_Helper_Empty::getInstance());
        
        $db->fetchRow(
            $widget->select()->where("{$key} = ?", $pkId)->limit(1),
                array($widget, 'push'));
        return $widget;
    }
    
    /**
     * 输出头部
     * 
     * @access public
     * @return void
     */
    public static function header()
    {
        $setting = Helper::options()->plugin('ExSearch');
?>
<link rel="stylesheet" href="<?php Helper::options()->pluginUrl('ExSearch/assets/ExSearch.css'); ?>">
<!--插件配置-->
<script>
ExSearchConfig = {
    root : "",
    api : "<?php 
        $db = Typecho_Db::get();
        $row = $db->fetchRow($db->select()->from('table.exsearch')
                ->order('table.exsearch.id', Typecho_Db::SORT_DESC)
                ->limit(1));
        $key = $row['key'];
        if($setting->static == 'true'){
            Helper::options()->pluginUrl('ExSearch/cache/cache-'.$key.'.json');
        }else{
            Helper::options()->index('/ExSearch/?action=api&key='.$key);
        }
    ?>"
}
</script>
<?php
    }

    /**
     * 在底部输出所需 JS
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function footer()
    {
?>
<script src="<?php Helper::options()->pluginUrl('ExSearch/assets/ExSearch.js'); ?>"></script>
<?php
    }
}