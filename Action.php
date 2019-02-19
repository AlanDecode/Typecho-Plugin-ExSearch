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
        switch ($_GET['action']) {
            case 'rebuild':
                $db = Typecho_Db::get();
                $dbname =$db->getPrefix() . 'exsearch';
                $sql = "SHOW TABLES LIKE '%" . $dbname . "%'";
                if(count($db->fetchAll($sql)) != 0){
                    $db->query($db->delete('table.exsearch')->where('id >= ?', 0));
                }
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