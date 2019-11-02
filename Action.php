<?php 
/**
 * Action.php
 * 
 * 处理请求
 * 
 * @author 熊猫小A
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
?>

<?php 
class ExSearch_Action extends Widget_Abstract_Contents implements Widget_Interface_Do 
{
    /**
     * 返回请求的 JSON
     * 
     * @access public
     */
    public function action(){
        // 要求先登录
        Typecho_Widget::widget('Widget_User')->to($user);
        if (!$user->have() || !$user->hasLogin()) {
            echo 'Invalid Request';
            exit;
        }

        switch ($_GET['action']) {
            case 'rebuild':
                ExSearch_Plugin::save();
?>
                重建索引完成，<a href="<?php Helper::options()->siteUrl(); ?>" target="_self">回到首页</a>。
<?php
                break;

            case 'api':
                header('Content-Type: application/json');

                $key = $_GET['key'];
                if(empty($key)){
                    echo json_encode(array());
                    return;
                } 
                $db = Typecho_Db::get();
                $row = $db->fetchRow($db->select()->from('table.exsearch')
                        ->where('table.exsearch.key = ?', $key));
                $content = $row['data'];
                echo $content;
                break;
        }
    }
}