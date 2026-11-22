<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
ini_set('display_errors','0');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex,nofollow');
try{
    if(!az_is_installed())throw new AzHttpError(503,'Commerce providers are unavailable.','not_installed');
    $user=az_require_auth(true);az_agent_workspace_assert($user);
    $action=is_string($_GET['action']??null)?$_GET['action']:'';
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if($method==='GET'&&$action==='features'){
        az_commerce_admin($user);session_write_close();
        az_json(['ok'=>true,'data'=>az_provider_features()]);
    }
    if($method==='GET'&&$action==='connectAccount'){
        $model=az_integer($_GET['modelId']??($user['id']??0),1,PHP_INT_MAX,'Creator');
        session_write_close();az_json(['ok'=>true,'data'=>az_connect_account_state($user,$model)]);
    }
    if($method==='GET'&&$action==='return'){
        az_commerce_admin($user);
        $id=az_text($_GET['id']??'',32,'Return case');
        session_write_close();
        az_json(['ok'=>true,'data'=>[
            'refund'=>az_provider_refund_status($user,$id),
            'label'=>az_return_label_snapshot($id)
        ]]);
    }
    if($method==='POST'){
        az_commerce_admin($user);az_require_csrf();
        $body=az_json_body();session_write_close();
        $result=match($action){
            'initiateRefund'=>az_provider_refund_initiate($user,$body),
            'reconcileRefund'=>az_provider_refund_reconcile($user,$body),
            'quoteReturnLabel'=>az_return_label_quote($user,$body),
            'buyReturnLabel'=>az_return_label_buy($user,$body),
            'reconcileReturnLabel'=>az_return_label_reconcile($user,$body),
            'linkConnectAccount'=>az_connect_account_link($user,$body),
            'sendConnectTransfer'=>az_connect_transfer_send($user,$body),
            'reconcileConnectTransfer'=>az_connect_transfer_reconcile($user,$body),
            default=>throw new AzHttpError(405,'Unsupported provider action.','method_not_allowed')
        };
        az_json(['ok'=>true,'data'=>$result]);
    }
    throw new AzHttpError(405,'Unsupported provider operation.','method_not_allowed');
}catch(AzHttpError $e){
    az_json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status);
}catch(Throwable $e){
    az_log_exception($e);
    az_json(['ok'=>false,'error'=>['code'=>'provider_unavailable','message'=>'This provider operation requires staff reconciliation.']],503);
}
