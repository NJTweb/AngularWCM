<?php

require_once("\\smtp\\class.phpmailer.php");
require_once("\\smtp\\class.smtp.php");

require_once("Query.php");

$db_connection = DEV_MODE ? "WCMBackup" : "WCM";

$MOUND_CONTACTS = array("jmurphy@venturecorporation.net","pillars@ventureglobalengineering.com","dharper@mayco-mi.com","newberry@ventureglobalengineering.com");
$DEFAULT_CONTACTS = array(
    "ESEWOs" => array("grzyb@mayco-mi.com", "pittam@mayco-mi.com", "mbommarito@mayco-mi.com"),
    "ToolIssues" => array("thomason@mayco-mi.com"),
    "UnsafeActs" => array("grzyb@mayco-mi.com", "pittam@mayco-mi.com"),
    "UCANs" => array("grzyb@mayco-mi.com", "pittam@mayco-mi.com"),
    "WCC" => array("grzyb@mayco-mi.com", "pittam@mayco-mi.com"),
    "EHS" => array("grzyb@mayco-mi.com", "pittam@mayco-mi.com"),
    "LPA" => array("tkranz@mayco-mi.com")
    );
$GREG_AND_I = array("hooks@njt-na.com","gwilloughby@mayco-mi.com");

$emails = execute_query("SELECT * FROM Emails", $db_connection, false, array(), PDO::FETCH_ASSOC);

if(isset($emails[INVALID_CONNECTION]) || isset($emails[EXECUTION_FAILED]) || isset($emails[NO_ROWS])) { exit("No emails."); }

foreach($emails as $email){
    $table_info = execute_query("GetTablesData", null, true, array("'{$email["TableName"]}'"), PDO::FETCH_ASSOC);
    
	$location = get_location_fields($table_info);
    
    $pk = get_pk($table_info);
    
    $contacts = execute_query("GetContactsFromTableAndID", null, true, array($email["TableName"], $location["Plants"], $location["Departments"], $location["Zones"], $pk, $email["FormID"]), PDO::FETCH_ASSOC);
    
    if(!isset($contacts["-3"])){
        $contacts_arr = array_map(function($el){
            return $el["SupervisorEmail"];
        }, $contacts);
    }else{
        $contacts_arr = array();
    }
    
    $record_info = execute_query("EXEC('SELECT * FROM [' + ? + '] WHERE [' + ? + ']=' + ?)", $db_connection, false, array($email["TableName"], $pk, $email["FormID"]), PDO::FETCH_ASSOC);
    
    //add mound contacts if a tool repair form
    if($email["TableName"] == "ToolIssues" /*&& $email["New"] == 1*/ && $record_info[0]["RepairedLocation"] == "Mound"){
        $contacts_arr = array_merge($contacts_arr, $MOUND_CONTACTS);
    }
    
    //add any default contacts
    if(isset($DEFAULT_CONTACTS[$email["TableName"]])){
        $contacts_arr = array_merge($contacts_arr, $DEFAULT_CONTACTS[$email["TableName"]]);
    }
    
    //add me and greg
    $contacts_arr = array_merge($contacts_arr, $GREG_AND_I);
    
    if($email["New"] == 1){
        $email["Subj"] .= " ("+$email["FormID"]+")";
    }
    
    send_email($email, $contacts_arr, $db_connection);
}

function get_location_fields ($table_info) {
    $loc_fields = array_filter($table_info, function ($field) {
        return $field["IsFK"] == 1 && 
            in_array($field["REF_TABLE"], array("Plants","Departments","Zones")) 
            && $field["REF_COLUMN"] == "ID";
    });
    
    $loc_array = array();
    
    foreach($loc_fields as $loc_field){
        $loc_array[$loc_field["REF_TABLE"]] = $loc_field["COLUMN_NAME"];
    }
    
    return $loc_array;
}

function get_pk ($table_info) {
    $pk_field = array_values(array_filter($table_info, function ($field) {
        return $field["IsPK"] == 1;
    }));
    
    return $pk_field[0]["COLUMN_NAME"];
}
    
/**
 * Notifies the specified contacts of the submitted or updated form
 * @param string $contacts Semi-colon delimited emails
 * @param string $subject The subject of the email
 * @param string $body The body of the email
 */
function send_email($email, $contacts, $db_connection){
    $mail = new PHPMailer(true);
    try{
        $mail->isSMTP();
        //$mail->Host = "mailna-com01b.mail.eo.outlook.com";
        $mail->Host = "smtp.gmail.com";
        $mail->SMTPSecure = "tls";
        $mail->Port = 587;
        //$mail->Port = 25; // 465, 587
        $mail->SMTPAuth = true;
        //$mail->Username = "hooks@njt-na.com";
        $mail->Username = "formalert@gmail.com";
        $mail->Password = "t05kwp456";
        $mail->From = "formalert@gmail.com";
        //$mail->From = "hooks@njt-na.com";
        $mail->FromName = "WCM Form Alert";
        $mail->isHTML(true);
        foreach($contacts as $address):
            $mail->addAddress($address);
        endforeach;
        $mail->Subject = $email["Subj"];
        $mail->Body = $email["Body"];
        if(!$mail->Send()){
            exit("Failed to send mail.");
        }
    } catch (phpmailerException $e) {
        exit("Mailer Error: " . $e->errorMessage());
    }
    echo "Message has been sent.<br />";
	execute_query("DELETE FROM Emails WHERE ID=?", $db_connection, false, array($email["ID"]), PDO::FETCH_ASSOC);
}

?>