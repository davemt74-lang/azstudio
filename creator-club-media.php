<?php
declare(strict_types=1);
/** A private-media delivery route; ownership + live membership entitlement checked on every request/range. */
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
try {
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD'],true))throw new AzHttpError(405,'GET or HEAD only.','method_not_allowed');
    $viewer=az_require_auth(true);
    $id=(string)($_GET['post']??'');
    $post=az_creator_club_post_row($id,$viewer);
    if(!$post['media_id'])throw new AzHttpError(404,'No video attached.','not_found');
    $media=az_personal_media_row((string)$post['media_id'],(int)$post['creator_id']);
    if(!in_array((string)$media['mime_type'],['video/mp4','video/webm','video/quicktime','video/ogg'],true))
        throw new AzHttpError(404,'Unsupported private media.','not_found');
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $handle=@fopen(az_media_path($media),'rb');
    if(!$handle)throw new AzHttpError(404,'Media unavailable.','media_missing');
    $size=(int)(fstat($handle)['size']??0);
    if($size<1){fclose($handle);throw new AzHttpError(404,'Media empty.','media_missing');}
    $start=0;$end=$size-1;
    if(isset($_SERVER['HTTP_RANGE'])){
        $range=trim((string)$_SERVER['HTTP_RANGE']);
        if(!preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/D',$range,$parts)||($parts[1]===''&&$parts[2]==='')){
            fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;
        }
        if($parts[1]===''){$len=min($size,(int)$parts[2]);$start=$size-$len;}
        else {$start=(int)$parts[1];if($parts[2]!=='')$end=min($end,(int)$parts[2]);}
        if($start>=$size||$start<0||$end<$start){fclose($handle);header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
    }
    while(ob_get_level()>0)ob_end_clean();@ini_set('zlib.output_compression','0');
    header('Content-Type: '.$media['mime_type']);
    header('Cache-Control: private, no-store, max-age=0');
    header('Accept-Ranges: bytes');
    header('Content-Length: '.($end-$start+1));
    header('Content-Disposition: inline; filename="creator-member-video"');
    header('X-Robots-Tag: noindex,nofollow');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'');
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD'){fclose($handle);exit;}
    fseek($handle,$start);$remaining=$end-$start+1;
    @set_time_limit(0);
    while($remaining>0&&!feof($handle)&&!connection_aborted()){
        $chunk=fread($handle,min($remaining,262144));if($chunk===false||$chunk==='')break;
        echo $chunk;$remaining-=strlen($chunk);flush();
    }fclose($handle);
}catch(AzHttpError $e){az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);}
catch(Throwable $e){az_log_exception($e);az_json(['ok'=>false,'error'=>['code'=>'club_media_error','message'=>'Private video unavailable.']],500);}
