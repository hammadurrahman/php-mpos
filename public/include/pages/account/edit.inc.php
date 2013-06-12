<?php

// Make sure we are called from index.php
if (!defined('SECURITY'))
  die('Hacking attempt');

if (!$_SESSION['AUTHENTICATED']) {
  header('Location: index.php?page=home');
}

if ( ! $user->checkPin($_SESSION['USERDATA']['id'], $_POST['authPin']) && $_POST['do']) {
  $_SESSION['POPUP'][] = array('CONTENT' => 'Invalid PIN','TYPE' => 'errormsg');
} else {
  switch ($_POST['do']) {
  case 'cashOut':
    if ($setting->getValue('manual_payout_active') == 1) {
      $_SESSION['POPUP'][] = array('CONTENT' => 'A manual payout is in progress. Please try again later.', 'TYPE' => 'errormsg');
    } else {
      $setting->setValue('manual_payout_active', 1);
      $continue = true;
      $aBalance = $transaction->getBalance($_SESSION['USERDATA']['id']);
      $dBalance = $aBalance['confirmed'];
      $sCoinAddress = $user->getCoinAddress($_SESSION['USERDATA']['id']);
      // Ensure we can cover the potential transaction fee of 0.1 with the balance
      if ($dBalance > 0.1) {
        if ($bitcoin->can_connect() === true) {
          try {
            $bitcoin->validateaddress($sCoinAddress);
          } catch (BitcoinClientException $e) {
            $_SESSION['POPUP'][] = array('CONTENT' => 'Invalid payment address: ' . $sUserSendAddress, 'TYPE' => 'errormsg');
            $continue = false;
          }
          if ($continue == true) {
            // Send balance to address, mind 0.1 fee for transaction!
            try {
              if ($setting->getValue('auto_payout_active') == 0) {
                $bitcoin->sendtoaddress($sCoinAddress, $dBalance);
              } else {
                $_SESSION['POPUP'][] = array('CONTENT' => 'Auto-payout active, please contact site support immidiately to revoke invalid transactions.', 'TYPE' => 'errormsg');
                $continue = false;
              }
            } catch (BitcoinClientException $e) {
              $_SESSION['POPUP'][] = array('CONTENT' => 'Failed to send ' . $config['currency'] . ', please contact site support immidiately', 'TYPE' => 'errormsg');
              $continue = false;
            }
          }
          // Set balance to 0, add to paid out, insert to ledger
          if ($continue == true && $transaction->addTransaction($_SESSION['USERDATA']['id'], $dBalance, 'Debit_MP', NULL, $sCoinAddress)) {
            $_SESSION['POPUP'][] = array('CONTENT' => 'Transaction completed', 'TYPE' => 'success');
            $aMailData['email'] = $user->getUserEmail($user->getUserName($_SESSION['USERDATA']['id']));
            $aMailData['amount'] = $dBalance;
            $aMailData['subject'] = 'Manual Payout Completed';
            $notification->sendNotification($_SESSION['USERDATA']['id'], 'manual_payout', $aMailData);
          }
        } else {
          $_SESSION['POPUP'][] = array('CONTENT' => 'Unable to connect to litecoind RPC service', 'TYPE' => 'errormsg');
        }
      } else {
        $_SESSION['POPUP'][] = array('CONTENT' => 'Insufficient funds, you need more than 0.1 ' . $conifg['currency'] . ' to cover transaction fees', 'TYPE' => 'errormsg');
      }
      $setting->setValue('manual_payout_active', 0);
    }
    break;

  case 'updateAccount':
    if ($user->updateAccount($_SESSION['USERDATA']['id'], $_POST['paymentAddress'], $_POST['payoutThreshold'], $_POST['donatePercent'], $_POST['email'])) {
      $_SESSION['POPUP'][] = array('CONTENT' => 'Account details updated', 'TYPE' => 'success');
    } else {
      $_SESSION['POPUP'][] = array('CONTENT' => 'Failed to update your account: ' . $user->getError(), 'TYPE' => 'errormsg');
    }
    break;

  case 'updatePassword':
    if ($user->updatePassword($_SESSION['USERDATA']['id'], $_POST['currentPassword'], $_POST['newPassword'], $_POST['newPassword2'])) {
      $_SESSION['POPUP'][] = array('CONTENT' => 'Password updated', 'TYPE' => 'success');
    } else {
      $_SESSION['POPUP'][] = array('CONTENT' => $user->getError(), 'TYPE' => 'errormsg');
    }
    break;
  }
}

// Tempalte specifics
$smarty->assign("CONTENT", "default.tpl");
?>
