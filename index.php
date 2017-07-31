<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/config.php';
error_reporting(0);
date_default_timezone_set('Asia/Kolkata');
$mail   = new PHPMailer();
$smarty = new Smarty();
$smarty->setTemplateDir(__DIR__.'/templates');

$mail->CharSet = 'UTF-8';
$mail->setFrom(Config::get('MAIL_FROM'), Config::get('MAIL_FROM_NAME'));

$addresses = explode(',', Config::get('MAIL_TO'));

foreach ($addresses as $address) {
    $mail->addAddress($address);
}

if (Config::get('MAIL_SMTP')) {
    $mail->isSMTP();
}
if (Config::get('AIL_HOST')) {
    $mail->Host = Config::MAIL_HOST;
}
if (Config::get('MAIL_SMTP_PORT')) {
    $mail->Port = Config::MAIL_SMTP_PORT;
}
if (Config::get('MAIL_USERNAME')) {
    $mail->Username = Config::MAIL_USERNAME;
}
if (Config::get('MAIL_PASSWORD')) {
    $mail->Password = Config::MAIL_PASSWORD;
}
if (Config::get('MAIL_SMTPSECURE')) {
    $mail->SMTPSecure = Config::MAIL_SMTPSECURE;
}

$mail->isHTML(true);

$mail->Subject = Config::MAIL_SUBJECT.date("Y-m-d");

try {
    // First, let's fetch who's to be excluded from these updates
    $resolverIds = getResolvers();
    $latestIssues = getLatestIssues();
    $issuesUpdatedByExternals = array();

    foreach ($latestIssues as $latestIssue) {
        $issue = getIssue($latestIssue['id']);
        if (isUpdatedByExternal($issue, $resolverIds)) {
            $issuesUpdatedByExternals[$issue['tracker']][] = $issue;
        }
    }

    $view = $smarty->createTemplate('latestIssues.tpl');
    $view->assign(
        array(
            'redmine_url'        => Config::REDMINE_URL,
            'issues_time_window' => Config::TIME_WINDOW,
            'issues_with_tracker'=> $issuesUpdatedByExternals,
        )
    );

    $html = $view->fetch();
    file_put_contents("issues.html", $html);
    $mail->Body = $html;

//    if (!$mail->send()) {
//        echo 'Message could not be sent.';
//        echo 'Mailer Error: '.$mail->ErrorInfo;
//    }
} catch (Exception $e) {
    echo $e->getMessage();
}

function callApi($url)
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL            => $url,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER     => array(
            'x-redmine-api-key: '.Config::API_KEY,
        ),
    ));

    $response = curl_exec($curl);
    $err      = curl_error($curl);

    curl_close($curl);

    if ($err) {
        throw new Exception('cURL Error #:'.$err);
    }

    return json_decode($response, true);
}

function getIssue($idIssue)
{
    $response = callApi(Config::REDMINE_URL.'/issues/'.$idIssue.'.json?include=journals');

    if (gettype($response) === 'NULL') {
        throw new Exception('Issue with ID ['.$idIssue.'] not found');
    }

    $redmineIssue    = $response['issue'];
    $journals        = $redmineIssue['journals'];

    // Set the last user to the author
    $lastUpdateBy_id = $redmineIssue['author']['id'];
    $lastUpdateBy    = $redmineIssue['author']['name'];

    // Get the editor of the last journal entry if present
    $journal = array_pop($journals);

    if ($journal) {
        $lastUpdateBy_id = $journal['user']['id'];
        $lastUpdateBy    = $journal['user']['name'];
    }

    if (isset($redmineIssue['assigned_to'])) {
        $assigned = $redmineIssue['assigned_to']['name'];
    } else {
        $assigned = false;
    }

    $issue = array(
        'id'              => $redmineIssue['id'],
        'status'          => $redmineIssue['status']['name'],
        'lastUpdate'      => $redmineIssue['updated_on'],
        'lastUpdateText'  => time_elapsed_string($redmineIssue['updated_on']),
        'project'         => $redmineIssue['project']['name'],
        'tracker'         => $redmineIssue['tracker']['name'],
        'projectId'       => $redmineIssue['project']['id'],
        'subject'         => $redmineIssue['subject'],
        'description'     => $redmineIssue['description'],
        'lastUpdateBy_id' => $lastUpdateBy_id,
        'lastUpdateBy'    => $lastUpdateBy,
        'author'          => $redmineIssue['author']['name'],
        'assigned'        => $assigned,
    );

    return $issue;
}

function isUpdatedByExternal($issue, $resolverIds)
{
    if (in_array($issue['lastUpdateBy_id'], $resolverIds)) {
        return false;
    }

    return true;
}

function getResolvers()
{
    $response     = callApi(Config::REDMINE_URL.'/groups/'.Config::IGNORE_GROUP.'.json?include=users');
    $RedmineUsers = $response['group']['users'];
    $users        = array();

    foreach ($RedmineUsers as $RedmineUser) {
        $users[] = $RedmineUser['id'];
    }

    return $users;
}

function getAllRows($url, $offset = 0, $limit = 100)
{
    $results = callApi($url.'&offset='.$offset.'&limit='.$limit);

    if ($results['offset'] + $results['limit'] < $results['total_count']) {
        $issues = array_merge($results['issues'], getAllRows($url, $offset + $limit, $limit));
    } else {
        $issues = $results['issues'];
    }

    return $issues;
}

function getLatestIssues($startDate = null)
{
    if ($startDate === null) {
        $startDate = date('Y-m-d\TH:i:s\Z', strtotime('-'.Config::TIME_WINDOW));
    }

    $latestIssues = getAllRows(Config::REDMINE_URL.'/issues.json?updated_on=>='.$startDate.'&per_page=100&sort=updated_on:desc');

    return $latestIssues;
}

function time_elapsed_string($datetime, $full = false)
{
    $now  = new DateTime();
    $ago  = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k.' '.$v.($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) {
        $string = array_slice($string, 0, 1);
    }

    return $string ? implode(', ', $string).' ago' : 'just now';
}
