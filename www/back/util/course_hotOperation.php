<?php
include "checksession.php";
require '../../../config/conn.php';
require '../../../config/credentials.php';
require '../../../vendor/autoload.php';

define('PHPMYWIND_ROOT', substr(dirname(__FILE__), 0, -9));
define('PHPMYWIND_DATA', PHPMYWIND_ROOT . 'data');
define('PHPMYWIND_TEMP', PHPMYWIND_ROOT . 'templates');
define('PHPMYWIND_UPLOAD', PHPMYWIND_ROOT . 'uploads');
define('UPLOAD_IMG', PHPMYWIND_UPLOAD . '/image');
define('UPLOAD_SOFT', PHPMYWIND_UPLOAD . '/soft');
define('UPLOAD_MEDIA', PHPMYWIND_UPLOAD . '/media');

use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use GuzzleHttp\Promise;

$fields = [];

//function names
$funcArr = [
    'getList',
    'getStates',
    'deleteData',
    'getCourses',
    'getItemById',
    'uploadFile',
    'deleteFiles',
    'saveData',
    'getInstitutionByCourseId',
    'uploadVideo',
];

$op = 0;

if (isset($_REQUEST['op']) && is_numeric($_REQUEST['op'])) {
    $op = $_REQUEST['op'];
}

if ($op < 0 || $op >= count($funcArr)) {
    die('Invalid param');
}

call_user_func($funcArr[$op]);

function GetRealSize($size)
{
    $kb = 1024; // Kilobyte
    $mb = 1024 * $kb; // Megabyte
    $gb = 1024 * $mb; // Gigabyte
    $tb = 1024 * $gb; // Terabyte

    if ($size < $kb) {
        return $size . 'B';
    } else if ($size < $mb) {
        return round($size / $kb, 2) . 'KB';
    } else if ($size < $gb) {
        return round($size / $mb, 2) . 'MB';
    } else if ($size < $tb) {
        return round($size / $gb, 2) . 'GB';
    } else {
        return round($size / $tb, 2) . 'TB';
    }
}


function getItemById()
{
    global $conn;
    $id = $_REQUEST['id'];
    $sql_detail = "SELECT  *
                    FROM course_hot i
                    WHERE i.id = ?
                    LIMIT 1 ;";
    $stmt = $conn->prepare($sql_detail);
    $stmt->execute([$id]);
    $one = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode($one, JSON_UNESCAPED_UNICODE);
}

function getList()
{
    global $conn;
    $limit = empty($_POST['limit']) ? "0" : $_POST['limit'];
    $offset = empty($_POST['offset']) ? "0" : $_POST['offset'];


    $sql_detail = "SELECT  id, title,title_en, tag,tag_en, image, sort, add_user, FROM_UNIXTIME(update_time) as update_time, read_count
                    FROM course_hot 
                    WHERE id IN REPLACEME
                    ORDER BY `sort` desc, id DESC
                    LIMIT $offset, $limit ;";
    $sql = "SELECT id FROM course_hot i ";
    $whereClause = " WHERE 1=1 ";


    if (!empty($_POST['keyword'])) {
        $whereClause .= " AND i.title LIKE ? ";
        $query = trim($_POST['keyword']);
        $stmt1 = $conn->prepare($sql . $whereClause);
        $stmt1->execute(array('%' . $query . '%'));

        $result = $stmt1->fetchAll(PDO::FETCH_COLUMN);
        if (count($result) > 0) {
            $ids = "(" . implode(",", $result) . ")";
            $stmt_detail = $conn->query(str_replace("REPLACEME", $ids, $sql_detail));
            $result_detail = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $result_detail = [];
        }

        echo json_encode(
            array(
                "total" => count($result_detail),
                "rows" => $result_detail,
            )
        );
    } else {
        // echo $sql . $whereClause;die;
        $stmt1 = $conn->prepare($sql . $whereClause);
        $stmt1->execute();
        $result = $stmt1->fetchAll(PDO::FETCH_COLUMN);

        if (count($result) > 0) {
            $ids = "(" . implode(",", $result) . ")";
            $stmt_detail = $conn->query(str_replace("REPLACEME", $ids, $sql_detail));
            $result_detail = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $result_detail = [];
        }
        echo json_encode(
            array(
                "total" => count($result),
                "rows" => $result_detail,
            )
        );
    }

    // (i.track LIKE ?) order by i.id desc;

}

function deleteData()
{
    global $conn;
    $sql_delete = "DELETE FROM course_hot WHERE id=:id;";
    $stmt_delete = $conn->prepare($sql_delete);
    $stmt_delete->bindValue(":id", $_POST['id'], PDO::PARAM_INT);
    $conn->beginTransaction();
    try {
        $stmt_delete->execute();
        $conn->commit();
        echo json_encode(['code' => 0, 'msg' => 'success']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['code' => -103, 'msg' => $e->getMessage()]);
    }
}

function uploadFile()
{
    @set_time_limit(0);
    $upload_info = UploadFileInfo();

/* 返回上传状态，是数组则表示上传成功
非数组则是直接返回发生的问题 */
    if (!is_array($upload_info)) {
        echo json_encode(['code' => -99, 'msg' => '未知错误'], JSON_UNESCAPED_UNICODE);
    }
    echo json_encode($upload_info, JSON_UNESCAPED_UNICODE);
}


function UploadFileInfo()
{
    global $conn;
    $cfg_max_file_size = 1024 * 1024 * 64;
    $upfile = 'fileupload';

    $cfg_upload_img_type = 'gif|png|jpg|bmp';
    $cfg_upload_soft_type = 'zip|gz|rar|iso|doc|docx|xls|xlsx|ppt|wps|txt';
    $cfg_upload_media_type = 'swf|avi|ogg|flv|mpg|mp3|mp4|rm|rmvb|wmv|wma|wav';

    //检测是否存在

    $tempfile_tn = isset($_FILES[$upfile]['tmp_name']) ? $_FILES[$upfile]['tmp_name'] : '';
    if ($tempfile_tn == '' || !is_uploaded_file($tempfile_tn)) {
        return ['code' => -10, 'msg' => '请选择上传文件或您上传的文件超过php.ini设定最大文件上传限制[' . ini_get('upload_max_filesize') . ']！'];
    }

    //获取上传文件信息
    $tempfile = $_FILES[$upfile];
    $tempfile_name = $tempfile['name'];
    $tempfile_size = $tempfile['size'];
    $tempfile_ext = strtolower(substr(strrchr($tempfile_name, '.'), 1));

    //强制限定的某些文件类型禁止上传
    if (in_array($tempfile_ext, explode('|', 'php|pl|cgi|asp|aspx|jsp|php3|shtm|shtml'))) {
        return ['code' => -20, 'msg' => '您上传的文件类型为：[' . $tempfile_ext . ']，该类文件不允许通过后台上传！'];
    }

    //检查文件类型,上传文件目录
    if (in_array($tempfile_ext, explode('|', strtolower($cfg_upload_img_type)))) {
        $upload_url = 'image';
        $upload_dir = UPLOAD_IMG;
    } else if (in_array($tempfile_ext, explode('|', strtolower($cfg_upload_soft_type)))) {
        $upload_url = 'soft';
        $upload_dir = UPLOAD_SOFT;
    } else if (in_array($tempfile_ext, explode('|', strtolower($cfg_upload_media_type)))) {
        $upload_url = 'media';
        $upload_dir = UPLOAD_MEDIA;
    } else {
        return ['code' => -30, 'msg' => '您上传的文件类型为：[' . $tempfile_ext . ']，该文件类型不允许上传！'];
    }

    $save_type = $upload_url;

    //检查文件大小

    if ($tempfile_size > $cfg_max_file_size) {
        return ['code' => -40, 'msg' => '您上传的文件超过系统设定最大文件上传限制[' . GetRealSize($cfg_max_file_size) . ']！'];
    }

    //创建文件夹
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777);
    }

    //检查目录可写权限
    if (@!is_writable($upload_dir)) {
        return ['code' => -50, 'msg' => '上传目录没有可写权限！'];
    }

    $fp = fopen($upload_dir . '/index.htm', 'w');
    fclose($fp);

    $ymd = date('Ymd');
    $upload_url .= '/' . $ymd;
    $upload_dir .= '/' . $ymd;

    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777);
    }

    //上传文件名称
    $filename = time() . '_' . rand(1, 9999) . '.' . $tempfile_ext;

    //上传文件路径
    $save_url = '/uploads/' . $upload_url . '/' . $filename;
    $save_dir = $upload_dir . '/' . $filename;

    if (file_exists($save_dir)) {
        return ['code' => -60, 'msg' => '同名文件已经存在了！'];
    }

    //移动临时文件到指定目录
    // if (@move_uploaded_file($tempfile_tn, $save_dir)) {
    //     //添加数据库记录
    //     $conn->query("INSERT INTO `so_uploads` (name, path, size, type, posttime) VALUES ('$filename', '$save_url', '$tempfile_size', '$save_type', '" . time() . "')");

    //     //上传成功，返回数组
    //     return array('code' => 0, 'name' => $filename, 'tempName' => $tempfile_name, 'size' => $tempfile_size, 'url' => $save_url, 'savedir' => $save_dir);
    // } else {
    //     return ['code' => -90, 'msg' => '发生未知错误，上传失败！'];
    // }

    try {
        move_uploaded_file($tempfile_tn, $save_dir);
        $conn->query("INSERT INTO `so_uploads` (name, path, size, type, posttime) VALUES ('$filename', '$save_url', '$tempfile_size', '$save_type', '" . time() . "')");
        //上传成功，返回数组
        return array('code' => 0, 'name' => $filename, 'tempName' => $tempfile_name, 'size' => $tempfile_size, 'url' => $save_url, 'savedir' => $save_dir);
    } catch (Exception $e) {
        return ['code' => -90, 'msg' => $e->getMessag()];
    }

}

function deleteFiles()
{
    global $conn;
    $path = $_REQUEST['path'];
    $path = json_decode($path);
    if ($path) {
        $path = array_map(function ($e) {return "'" . $e . "'";}, $path);
        try {
            $stmt = $conn->prepare("DELETE FROM so_uploads WHERE `path` in (?) ;");
            $stmt->execute([implode("," . $path)]);
            echo json_encode(['code' => 0], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['code' => -1, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }
}

function saveData()
{
    global $conn;
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        echo json_encode(['code' => -1, 'msg' => '请求体错误'], JSON_UNESCAPED_UNICODE);
        die;
    }
    // echo json_encode($data);
    // echo empty($data['vidoe'][0]['title']) ? "empty" : "notempty";
    // die;
    // echo PHP_EOL;

	if (!empty($data['id'])) {
        //更新现有数据
        $sql = "UPDATE course_hot SET update_time = ".time().",";

        foreach ($data as $key => $value) {
            if (empty($data[$key])) {
                unset($data[$key]);
                continue;
            }
        }
        foreach ($data as $key => $value) {
            if ($key == 'id') {
                continue;
            }
            $sql .= "$key=:$key,";
        }
        $sql = substr($sql, 0, -1);
        $sql .= " WHERE id=:id";
        //echo $sql;die;
    } else {
        // 插入新数据
        $data['add_time'] = $data['update_time'] = time();
        $sql = "INSERT INTO course_hot (`title`,`title_en`,`tag`,`tag_en`, `image`,`sort`,`content`,`content_en`,`add_user`,`add_time`,`update_time`) 
        		VALUE(:title,:title_en,:tag,:tag_en,:image,:sort,:content,:content_en,:add_user,:add_time,:update_time);";
        
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($data);
        
        echo json_encode(['code' => 0, 'msg' => '成功'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['code' => -20, 'msg' => '数据库错误' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

}
