<?php
declare(strict_types=1);
/** Staff-only, read-only operating snapshot. It never queries provider APIs or grants permission to spend. */
function az_provider_readiness_snapshot(array $stripe,array $carrier,array $actions,array $counts):array
{
    $stripeReady=!empty($stripe['ready']);
    $carrierReady=!empty($carrier['configured']);
    $stripeMode=$stripeReady&&in_array(($stripe['mode']??''),['test','live'],true)?$stripe['mode']:'unconfigured';
    $carrierMode=$carrierReady&&in_array(($carrier['mode']??''),['test','production'],true)?$carrier['mode']:'unconfigured';
    $refunds=(bool)($actions['refundsEnabled']??false);
    $transfers=(bool)($actions['connectEnabled']??false);
    $labels=$carrierReady&&!empty($carrier['allowPurchase'])&&!empty($actions['returnLabelPurchasesEnabled']);
    $pending=[
        ['id'=>'refunds','label'=>'Stripe refunds','count'=>max(0,(int)($counts['refundsNeedsReconciliation']??0)),'tab'=>'returns'],
        ['id'=>'labels','label'=>'Return postage labels','count'=>max(0,(int)($counts['returnLabelsNeedReconciliation']??0)),'tab'=>'returns'],
        ['id'=>'transfers','label'=>'Connect transfers','count'=>max(0,(int)($counts['connectTransfersNeedReconciliation']??0)),'tab'=>'payouts']
    ];
    $pendingTotal=array_sum(array_column($pending,'count'));
    $liveActions=($stripeMode==='live'&&($refunds||$transfers))||($carrierMode==='production'&&$labels);
    $checks=[
        ['id'=>'stripe','label'=>'Stripe keys, HTTPS checkout URL and webhook configuration',
         'state'=>$stripeReady?'configured':'attention',
         'detail'=>$stripeReady?'Credentials have the expected format; live provider access is not verified.':'Configure Stripe credentials, signed webhook secret and HTTPS public base URL.'],
        ['id'=>'refund','label'=>'Staff-initiated refund switch',
         'state'=>$refunds?'enabled':'disabled',
         'detail'=>$refunds?'Private refund spending limit is enforced.':'Refund initiation remains safely disabled in private settings.'],
        ['id'=>'connect','label'=>'Staff-initiated Connect transfer switch',
         'state'=>$transfers?'enabled':'disabled',
         'detail'=>$transfers?'Private Connect transfer limit is enforced.':'Connect transfers remain safely disabled in private settings.'],
        ['id'=>'carrier','label'=>'EasyPost account and ship-from origin',
         'state'=>$carrierReady?'configured':'attention',
         'detail'=>$carrierReady?'Account format and origin are configured; carrier access is not verified.':'Configure EasyPost credentials and a validated ship-from address.'],
        ['id'=>'labels','label'=>'Staff-approved return-label purchases',
         'state'=>$labels?'enabled':'disabled',
         'detail'=>$labels?'Private postage limit and staff approval are required.':'Return-label purchases remain safely disabled.'],
        ['id'=>'webhook','label'=>'EasyPost signed carrier webhook',
         'state'=>!empty($carrier['webhookConfigured'])?'configured':'attention',
         'detail'=>!empty($carrier['webhookConfigured'])?'Webhook secret is configured; test a signed event on the host.':'Configure and test the signed tracking webhook before relying on carrier events.'],
        ['id'=>'reconciliation','label'=>'Unresolved provider operations',
         'state'=>$pendingTotal===0?'clear':'attention',
         'detail'=>$pendingTotal===0?'No pending provider records were counted.':'Resolve or investigate existing provider operations before considering activation.']
    ];
    return [
        'checkedAt'=>az_now(),
        'environment'=>['stripe'=>$stripeMode,'easypost'=>$carrierMode],
        'counts'=>$pending,'pendingTotal'=>$pendingTotal,
        'checks'=>$checks,
        // These are *configuration* signals only; never an assertion that payments are production-ready.
        'configurationReady'=>$stripeReady&&$carrierReady&&!empty($carrier['webhookConfigured'])&&$pendingTotal===0&&!$liveActions,
        'liveActionsEnabled'=>$liveActions,
        'hostedTestsVerified'=>false,'productionApproved'=>false,
        'requiredTests'=>[
            'On the hosted installation, complete and reconcile one Stripe TEST refund; verify the signed refund event against the order ledger.',
            'Quote and purchase an EasyPost TEST return label and verify the stored tracking reference and signed carrier webhook.',
            'Transfer an approved TEST commission to a verified test Connect account; distinguish platform transfer from bank payout.',
            'Repeat webhook deliveries, simulate provider timeouts, and verify idempotency and read-only recovery with no duplicate money movement.'
        ],
        'notice'=>'This page is read-only. Configured keys and green CI checks are not proof of successful hosted TEST transactions. Never enable live financial actions without independent hosted verification and approval.'
    ];
}
function az_provider_readiness_report(array $user):array
{
    az_commerce_admin($user);
    $db=az_db();
    $queries=[
        'refundsNeedsReconciliation'=>"SELECT COUNT(*) FROM commerce_provider_refunds WHERE status IN ('pending','needs_review','provider_pending')",
        'returnLabelsNeedReconciliation'=>"SELECT COUNT(*) FROM commerce_return_labels WHERE status IN ('pending','needs_review')",
        'connectTransfersNeedReconciliation'=>"SELECT COUNT(*) FROM commerce_connect_transfers WHERE status IN ('pending','needs_review')"
    ];
    $counts=[];
    foreach($queries as $name=>$sql)$counts[$name]=(int)$db->query($sql)->fetchColumn();
    return az_provider_readiness_snapshot(az_stripe_config(),az_carrier_config(),az_provider_action_config(),$counts);
}
