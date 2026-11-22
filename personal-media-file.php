<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
ini_set('display_errors','0');
try {
    $user=az_require_auth(true); az_personal_media_role($user);
    $method=$_SERVER['REQUEST_METHOD']??'GET';
    if (!in_array($method,['GET','HEAD'],true)) throw new AzHttpError(405,'Use GET or HEAD to view footage.','method_not_allowed');
    $id=az_text($_GET['id']??'',32,'Video ID');
    $row=az_personal_media_row($id,(int)$user['id']);
    $type=$_GET['type']??'video';
    if ($type==='cover') {
        if (empty($row['cover_storage_name'])) throw new AzHttpError(404,'This video has no cover.','not_found');
        $storage=['storage_name'=>$row['cover_storage_name']];
        $mime=$row['cover_mime_type']; $fileName='cover.'.(explode('/',(string)$mime)[1]??'jpg');
    } elseif ($type==='video') {
        $storage=$row; $mime=$row['mime_type']; $fileName=pathinfo((string)$row['file_name'],PATHINFO_BASENAME);
    } else throw new AzHttpError(404,'Media not found.','not_found');
    session_write_close();
    $handle=@fopen(az_media_path($storage),'rb');
    if (!$handle) throw new AzHttpError(404,'This video is no longer available.','media_missing');
    $stat=fstat($handle);$size=(int)($stat['size']??0);
    if ($size<1) { fclose($handle);throw new AzHttpError(404,'Media file is empty.','media_missing'); }
    while (ob_get_level()>0) ob_end_clean();
    @ini_set('zlib.output_compression','0');
    header('Content-Type: '.$mime);
    header('Cache-Control: private, no-store');
    header('Accept-Ranges: bytes');
    header('X-Robots-Tag: noindex,nofollow');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    $safe=preg_replace('/[^A-Za-z0-9._ -]/','',(string)$fileName)??'media.bin';
    header('Content-Disposition: '.(($_GET['download']??'')==='1'?'attachment':'inline').'; filename="'.substr($safe?:'video',0,140).'"');
    $start=0;$end=$size-1;
    if (isset($_SERVER['HTTP_RANGE'])) {
        if ($type==='cover' || !preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/D',trim($_SERVER['HTTP_RANGE']),$match) || ($match[1]===''&&$match[2]==='')) {
            fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;
        }
        if ($match[1]==='') {$suffix=min($size,(int)$match[2]);$start=$size-$suffix;}
        else {$start=(int)$match[1];if ($match[2]!=='')$end=min($end,(int)$match[2]);}
        if ($start>=$size || $start<0 || $end<$start) {fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    $left=$end-$start+1;
    header('Content-Length: '.$left);
    if ($method==='HEAD') {fclose($handle);exit;}
    fseek($handle,$start);
    while ($left>0 && !feof($handle) && !connection_aborted()) {
        $data=fread($handle,min(262144,$left));
        if ($data===false || $data==='') break;
        echo $data;$left-=strlen($data);flush();
    }
    fclose($handle);
} catch (AzHttpError $e) {
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
} catch(Throwable $e) {
    az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'media_error','message'=>'Could not load this private video.']],500);
}
