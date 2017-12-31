<?php

/**
 * Form Tools API
 * --------------
 *
 * This file is provided for backward compatibility only for users who are using the API 1.x versions and want to
 * upgrade to Form Tools 3. It contains wrapper methods with the old function methods that now call the new API class
 * methods.
 *
 * Please don't use this file! It will be dropped at some point. Use the API.class.php methods directly.
 *
 * Documentation:
 * https://docs.formtools.org/api/
 */

require_once("API.class.php");

use FormTools\API;

$g_api_version = API::getVersion(); // TODO
//$g_api_recaptcha_error = null; // TODO

// wrapper functions for the new API class methods
function ft_api_show_submissions($form_id, $view_id, $export_type_id, $page_num = 1, $options = array()) {
    return API::showSubmissions($form_id, $view_id, $export_type_id, $page_num, $options);
}

function ft_api_show_submission($form_id, $view_id, $export_type_id, $submission_id) {
    return API::showSubmission($form_id, $view_id, $export_type_id, $submission_id);
}

function ft_api_show_submission_count($form_id, $view_id = "") {
    return API::showSubmissionCount($form_id, $view_id);
}

function ft_api_create_blank_submission($form_id, $finalized = false, $default_values = array())
{
  global $g_table_prefix;

  // confirm the form is valid
  if (!ft_check_form_exists($form_id))
  {
    $page_vars = array("message_type" => "error", "error_code" => 500, "error_type" => "user");
    ft_display_page("error.tpl", $page_vars);
    exit;
  }

  $now = ft_get_current_datetime();
  $ip_address = $_SERVER["REMOTE_ADDR"];
  $is_finalized = ($finalized) ? "yes" : "no";

  $col_str = "";
  $val_str = "";
  if (!empty($default_values))
  {
    $cols = array_keys($default_values);
    $col_str = ", " . join(", ", $cols);
    $vals = array_values($default_values);

    $escaped_vals = array();
    foreach ($vals as $val)
      $escaped_vals[] = "'" . ft_sanitize($val) . "'";

    $val_str = ", " . join(", ", $escaped_vals);
  }

  $query = @mysql_query("
    INSERT INTO {$g_table_prefix}form_{$form_id} (submission_date, last_modified_date, is_finalized, ip_address{$col_str})
    VALUES ('$now', '$now', '$is_finalized', '$ip_address'{$val_str})
      ");

  if ($query)
    return mysql_insert_id();
  else
  {
    $page_vars = array(
      "message_type" => "error",
      "error_code" => 501,
      "error_type" => "user",
      "debugging" => mysql_error()
    );
    ft_display_page("error.tpl", $page_vars);
    exit;
  }
}


/**
 * This function was written to *significantly* simplify the job of integrating code-submission forms with Form
 * Tools. This, when used in conjunction with ft_api_form_process_page(), effectively does away with the need
 * to embed any special PHP logic in your forms to ensure the data gets submitted to Form Tools properly.
 *
 * The function does the following:
 *
 *    - starts sessions (used to store the form data as the user progresses through the form)
 *    - get / returns the unique submission ID. It creates a unique submission ID record in the database for
 *         this submission; but it only shows up in the Form Tools UI if you explicitly tell Form Tools that the
 *         submission is complete
 *    - returns all values already submitted in the form, to let you pre-populate the fields if you want
 *
 * @param integer $form_id this field is only required for the FIRST page in your form.
 * @param string $namespace - a hash key to defined where in sessions the form information should be stored. Most
 *      users probably won't care about this; it's for programmers who want a little more control over the content
 *      of sessions (it's stored in: $_SESSION["form_tools_form"] by default). Note: if you choose to define your
 *      own namespace, make sure you pass in the "namespace" setting in the final $settings parameter for the
 *      ft_api_form_process_page() function - otherwise it won't know what submission or form to process!
 * @return array [0] the submission ID
 *               [1] a hash of form values
 */
function ft_api_init_form_page($form_id = "", $mode = "live", $namespace = "form_tools_form")
{
  global $g_api_header_charset;

  if (!isset($_SESSION))
    ft_api_start_sessions();

  if (!isset($_SESSION[$namespace]) || empty($_SESSION[$namespace]))
  {
    $_SESSION[$namespace] = array();

    // here, form_id should have been set: this is the FIRST page of (potentially) a multi-page form
    switch ($mode)
    {
      case "test":
        $_SESSION[$namespace]["form_tools_form_id"]       = "test";
        $_SESSION[$namespace]["form_tools_submission_id"] = "test";
        break;

       case "initialize":
         // if form ID is blank here, chances are a user just put through their test submission an has returned
         // to a multi-page form page. In this situation, the sessions have been emptied, but this function is
         // called PRIOR to ft_api_process_form, which does the job of auto-redirecting to whatever page is
         // specified by the user
         if (empty($form_id))
           return $_SESSION[$namespace];
         $_SESSION[$namespace]["form_tools_form_id"]       = $form_id;
         $_SESSION[$namespace]["form_tools_submission_id"] = "initializing";
         $_SESSION[$namespace]["form_tools_initialize_form"] = 1;
         break;

       case "live":
         // if form ID is blank here, chances are a user is just returning to a multi-page form page
         // after putting through the submission. In this situation, the sessions have been emptied, but
         // this function is called PRIOR to ft_api_process_form, which does the job of auto-redirecting
         // to whatever page is specified by the user
         if (empty($form_id))
           return $_SESSION[$namespace];

         $submission_id = ft_api_create_blank_submission($form_id);
         $_SESSION[$namespace]["form_tools_form_id"]       = $form_id;
         $_SESSION[$namespace]["form_tools_submission_id"] = $submission_id;
         break;

       default:
         $page_vars = array("message_type" => "error", "error_code" => 200, "error_type" => "user");
         ft_display_page("error.tpl", $page_vars);
         exit;
         break;
    }
  }

  return $_SESSION[$namespace];
}


/**
 * Clears sessions after succesfully completing a form.
 *
 * @param string $namespace (optional);
 */
function ft_api_clear_form_sessions($namespace = "form_tools_form")
{
  $_SESSION[$namespace] = "";
  unset($_SESSION[$namespace]);
}


/**
 * Processes a form submission, either for a single page of a multi-page form or the entire form itself. If the
 * "submit_button_name key exists in $params (i.e. if the user just submitted the form), it updates the database for
 * the submission ID.
 *
 * Assumption: the ft_api_init_form_page function has been called on the page prior to calling this function.
 *
 * @param array $params
 *
 *     Required keys:
 *        "submit_button": the "name" attribute value of the form submit button
 *        "form_data": the contents of $_POST (or $_GET, if "method" setting is set to "GET" ... )
 *        "file_data": the contents of $_FILES (only needed if your form contained file fields)
 *
 *     Optional keys:
 *        "next_page": the URL (relative or absolute) of which page to redirect to (e.g. the next page
 *               in the form or the "thankyou" page).
 *        "finalize": this tells the function to finalize the submission. This prevents it being subsequently
 *               editable via this function and makes the submission appear in the Form Tools UI.
 *        "no_sessions_url": for multi-page forms it's a good idea to pass along this value. It should be the URL
 *               of a page (usually the FIRST page in the form sequence) where the user will be redirected to if
 *               they didn't start the form from the first page. It ensures the form submission gets created &
 *               submitted properly.
 *        "may_update_finalized_submissions": true / false (true by default)
 *        "namespace": if you specified a custom namespace for ft_api_init_form_page, for where the form values will
 *               be stored temporarily in sessions, you need to pass that same value to this function - otherwise
 *               it won't be able to retrieve the form and submission ID
 *        "send_emails": (boolean). By default, Form Tools will trigger any emails that have been attached to the
 *               "on submission" event ONLY when the submission is finalized (finalize=true). This setting provides
 *               you with direct control over when the emails get sent. If not specified, will use the default
 *               behaviour.
 *
 * @return mixed ordinarily, this function will just redirect the user to whatever URL is specified in the
 *        "next_page" key. But if that value isn't set, it returns an array:
 *               [0] success / false
 *               [1] if failure, the API Error Code, otherwise blank
 */
function ft_api_process_form($params)
{
  global $g_table_prefix, $g_multi_val_delimiter, $LANG, $g_api_debug, $g_api_recaptcha_private_key,
    $g_api_recaptcha_error;

  // the form data parameter must ALWAYS be defined
  if (!isset($params["form_data"]))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 306, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 306);
  }

  // special case: if "form_tools_delete_image_field__[fieldname]" exists, the user is just deleting an image
  // already uploaded through the form using the HTML generated by the ft_api_display_image_field function.
  // In this case, we process the page normally - even though the form data wasn't submitted & the page may
  // contain nothing in $form_data
  $is_deleting_file = false;
  $file_field_to_delete = "";
  $namespace = isset($params["namespace"]) ? $params["namespace"] : "form_tools_form";
  $form_id   = isset($_SESSION[$namespace]["form_tools_form_id"]) ? $_SESSION[$namespace]["form_tools_form_id"] : "";
  $submission_id   = isset($_SESSION[$namespace]["form_tools_submission_id"]) ? $_SESSION[$namespace]["form_tools_submission_id"] : "";
  while (list($key, $value) = each($params["form_data"]))
  {
    if (preg_match("/form_tools_delete_image_field__(.*)$/", $key, $matches))
    {
      $file_field_to_delete = $matches[1];
      $is_deleting_file = true;

      $field_id = ft_get_form_field_id_by_field_name($file_field_to_delete, $form_id);

      ft_delete_file_submission($form_id, $submission_id, $field_id, true);

      unset($_SESSION[$namespace][$file_field_to_delete]);
      unset($params["form_data"][$key]);
    }
  }

  // check the submission exists
  if (is_numeric($form_id) && is_numeric($submission_id) && !ft_check_submission_exists($form_id, $submission_id))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 305, "error_type" => "user",
        "debugging" => "{$LANG["phrase_submission_id"]}: $submission_id");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 305);
  }


  // extract the submission ID and form ID from sessions
  $form_data       = $params["form_data"];
  $form_id         = isset($_SESSION[$namespace]["form_tools_form_id"]) ? $_SESSION[$namespace]["form_tools_form_id"] : "";
  $submission_id   = isset($_SESSION[$namespace]["form_tools_submission_id"]) ? $_SESSION[$namespace]["form_tools_submission_id"] : "";
  $has_captcha     = isset($form_data["recaptcha_response_field"]) ? true : false;
  $no_sessions_url = isset($params["no_sessions_url"]) ? $params["no_sessions_url"] : false;

  if (!isset($_GET["ft_sessions_url_override"]) && (empty($form_id) || empty($submission_id)))
  {
    if (!empty($no_sessions_url))
    {
      header("location: $no_sessions_url");
      exit;
    }
    else
    {
      if ($g_api_debug)
      {
        $page_vars = array("message_type" => "error", "error_code" => 300, "error_type" => "user");
        ft_display_page("error.tpl", $page_vars);
        exit;
      }
      else
        return array(false, 300);
    }
  }

  // if the user is neither deleting a file or making a regular form submission, it means they've just
  // arrived at the page. Cool! Do nothing!
  if (!$is_deleting_file && !isset($params["form_data"][$params["submit_button"]]))
    return;

  $submit_button_name = $params["submit_button"];
  $next_page          = isset($params["next_page"]) ? $params["next_page"] : "";
  $file_data          = isset($params["file_data"]) ? $params["file_data"] : array();
  $finalize           = isset($params["finalize"]) ? $params["finalize"] : false;
  $namespace          = isset($params["namespace"]) ? $params["namespace"] : "form_tools_form";
  $may_update_finalized_submissions = isset($params["may_update_finalized_submissions"]) ? $params["may_update_finalized_submissions"] : true;


  // if we're in test mode, we don't do anything with the database - just store the fields in
  // sessions to emulate
  if ($form_id == "test" || $submission_id == "test")
  {
    reset($form_data);
    while (list($field_name, $value) = each($form_data))
      $_SESSION[$namespace][$field_name] = $value;
  }

  else if (isset($_SESSION[$namespace]["form_tools_initialize_form"]))
  {
    // only process the form if this submission is being set to be finalized
    if ($finalize)
    {
      // if the user is just putting through a test submission and we've reached the finalization step,
      // overwrite $form_data with ALL the
      $all_form_data = array_merge($_SESSION[$namespace], $form_data);
      ft_initialize_form($all_form_data);
    }

    reset($form_data);
    while (list($field_name, $value) = each($form_data))
      $_SESSION[$namespace][$field_name] = $value;
  }

  // otherwise it's a standard form submission for a fully set up form, with - ostensibly - a valid
  // submission ID and form ID. Update the submission for whatever info is in $form_data and $file_data
  else
  {
    // check the form ID is valid
    if (!ft_check_form_exists($form_id))
    {
      if ($g_api_debug)
      {
        $page_vars = array("message_type" => "error", "error_code" => 301, "error_type" => "user");
        ft_display_page("error.tpl", $page_vars);
        exit;
      }
      else
        return array(false, 301);
    }

    // check the submission ID isn't finalized
    if (!$may_update_finalized_submissions && ft_check_submission_finalized($form_id, $submission_id))
    {
      if ($g_api_debug)
      {
        $page_vars = array("message_type" => "error", "error_code" => 302, "error_type" => "user",
          "debugging" => "{$LANG["phrase_submission_id"]}: $submission_id");
        ft_display_page("error.tpl", $page_vars);
        exit;
      }
      else
        return array(false, 302);
    }

    $form_info = ft_get_form($form_id);

    // check to see if this form has been disabled
    if ($form_info["is_active"] == "no")
    {
      if (isset($form_data["form_tools_inactive_form_redirect_url"]))
      {
        header("location: {$form_data["form_tools_inactive_form_redirect_url"]}");
        exit;
      }
      if ($g_api_debug)
      {
        $page_vars = array("message_type" => "error", "error_code" => 303, "error_type" => "user");
        ft_display_page("error.tpl", $page_vars);
        exit;
      }
      else
        return array(false, 303);
    }

    // now we sanitize the data (i.e. get it ready for the DB query)
    $form_data = ft_sanitize($form_data);

    extract(ft_process_hook_calls("start", compact("form_info", "form_id", "form_data"), array("form_data")), EXTR_OVERWRITE);

    // get a list of the custom form fields (i.e. non-system) for this form
    $form_fields = ft_get_form_fields($form_id, array("include_field_type_info" => true));

    $custom_form_fields = array();
    $file_fields = array();
    foreach ($form_fields as $field_info)
    {
      $field_id        = $field_info["field_id"];
      $is_system_field = $field_info["is_system_field"];
      $field_name      = $field_info["field_name"];

      // ignore system fields
      if ($is_system_field == "yes")
        continue;

      if ($field_info["is_file_field"] == "no")
      {
        $custom_form_fields[$field_name] = array(
          "field_id"    => $field_id,
          "col_name"    => $field_info["col_name"],
          "field_title" => $field_info["field_title"],
          "include_on_redirect" => $field_info["include_on_redirect"],
          "field_type_id" => $field_info["field_type_id"],
          "is_date_field" => $field_info["is_date_field"]
        );
      }
      else
      {
        $file_fields[] = array(
          "field_id"   => $field_id,
          "field_info" => $field_info
        );
      }
    }

    // now examine the contents of the POST/GET submission and get a list of those fields
    // which we're going to update
    $valid_form_fields  = array();
    while (list($form_field, $value) = each($form_data))
    {
      if (array_key_exists($form_field, $custom_form_fields))
      {
        $curr_form_field = $custom_form_fields[$form_field];
        $cleaned_value = $value;
        if (is_array($value))
        {
          if ($form_info["submission_strip_tags"] == "yes")
          {
            for ($i=0; $i<count($value); $i++)
              $value[$i] = strip_tags($value[$i]);
          }

          $cleaned_value = implode("$g_multi_val_delimiter", $value);
        }
        else
        {
          if ($form_info["submission_strip_tags"] == "yes")
            $cleaned_value = strip_tags($value);
        }

        $valid_form_fields[$curr_form_field["col_name"]] = "'$cleaned_value'";
      }
    }

    $now          = ft_get_current_datetime();
    $ip_address   = $_SERVER["REMOTE_ADDR"];
    $is_finalized = ($finalize) ? "yes" : "no";

    $set_query = "";
    while (list($col_name, $value) = each($valid_form_fields))
      $set_query .= "$col_name = $value,\n";


    // in this section, we update the database submission info & upload files. Note: we don't do ANYTHING
    // if the form_tools_ignore_submission key is set in the POST data
    if (!isset($form_data["form_tools_ignore_submission"]))
    {
      // construct our query. Note that we do TWO queries: one if there was no CAPTCHA sent with this
      // post (which automatically finalizes the result), and one if there WAS. For the latter, the submission
      // is finalized later
      if ($has_captcha && $finalize)
      {
        $query = "
          UPDATE {$g_table_prefix}form_$form_id
          SET    $set_query
                 last_modified_date = '$now',
                 ip_address = '$ip_address'
          WHERE  submission_id = $submission_id
            ";
      }
      else
      {
        // only update the is_finalized setting if $may_update_finalized_submissions === false
        if (!$finalize && $may_update_finalized_submissions)
          $is_finalized_clause = "";
        else
          $is_finalized_clause = ", is_finalized = '$is_finalized'";

        $query = "
          UPDATE {$g_table_prefix}form_$form_id
          SET    $set_query
                 last_modified_date = '$now',
                 ip_address = '$ip_address'
                 $is_finalized_clause
          WHERE  submission_id = $submission_id
            ";
      }

      // only process the query if the form_tools_ignore_submission key isn't defined
      if (!mysql_query($query))
      {
        if ($g_api_debug)
        {
          $page_vars = array("message_type" => "error", "error_code" => 304, "error_type" => "system",
            "debugging"=> "Failed query in <b>" . __FUNCTION__ . ", " . __FILE__ . "</b>, line " . __LINE__ .
                ": <i>" . nl2br($query) . "</i> " .  mysql_error());
          ft_display_page("error.tpl", $page_vars);
          exit;
        }
        else
          return array(false, 304);
      }

      // used for uploading files. The error handling is incomplete here, like previous versions. Although the hooks
      // are permitted to return values, they're not used
      extract(ft_process_hook_calls("manage_files", compact("form_id", "submission_id", "file_fields", "namespace"), array("success", "message")), EXTR_OVERWRITE);
    }

    // store all the info in sessions
    reset($form_data);
    while (list($field_name, $value) = each($form_data))
      $_SESSION[$namespace][$field_name] = $value;
  }

  // was there a reCAPTCHA response? If so, a recaptcha was just submitted, check it was entered correctly
  $passes_captcha = true;
  if ($has_captcha)
  {
    $passes_captcha = false;
    $recaptcha_challenge_field = $form_data["recaptcha_challenge_field"];
    $recaptcha_response_field  = $form_data["recaptcha_response_field"];

    $folder = dirname(__FILE__);
    require_once("$folder/recaptchalib.php");

    $resp = recaptcha_check_answer($g_api_recaptcha_private_key, $_SERVER["REMOTE_ADDR"], $recaptcha_challenge_field, $recaptcha_response_field);

    if ($resp->is_valid)
    {
      $passes_captcha = true;

      // if the developer wanted the submission to be finalized at this step, do so - it wasn't earlier!
      if ($finalize)
      {
        mysql_query("
          UPDATE {$g_table_prefix}form_$form_id
          SET    is_finalized = 'yes'
          WHERE  submission_id = $submission_id
            ");
      }
    }
    else
    {
      // register the recaptcha as a global, which can be picked up silently by ft_api_display_captcha to
      // let them know they entered it wrong
      $g_api_recaptcha_error = $resp->error;
    }
  }

  if ($passes_captcha && !empty($next_page) && !$is_deleting_file)
  {
    // if the user wasn't putting through a test submission or initializing the form, we can send safely
    // send emails at this juncture, but ONLY if it was just finalized OR if the send_emails parameter
    // allows for it
    if ($form_id != "test" && $submission_id != "test" && !isset($_SESSION[$namespace]["form_tools_initialize_form"])
      && !isset($form_data["form_tools_ignore_submission"]))
    {
      // send any emails attached to the on_submission trigger
      if (isset($params["send_emails"]) && $params["send_emails"] === true)
        ft_send_emails("on_submission", $form_id, $submission_id);
      else if ($is_finalized == "yes" && (!isset($params["send_emails"]) || $params["send_emails"] !== false))
        ft_send_emails("on_submission", $form_id, $submission_id);
    }

    header("location: $next_page");
    exit;
  }

  return array(true, "");
}


/**
 * This function saves you the effort of writing the PHP needed to display an image that's been uploaded through
 * your form. The function is used in forms that include file upload fields - and specifically, file upload fields
 * where users are uploading *images only*. It displays any images already uploaded through the form field,
 * and (by default) a "delete" button to let them delete the image.
 *
 *
 * @param hash $params a hash with the following keys:
 *   Required keys
 *     "field_name" - the name of the file input field
 *
 *   Optional keys
 *     "width"  - adds a "width" attribute to the image, with this value
 *     "height" - adds a "width" attribute to the image, with this value
 *     "namespace"  - only required if you specified a custom namespace in the original ft_api_init_form_page
 *          function.
 *     "hide_delete_button" - by default, whenever the field already has an image uploaded, this function will
 *          display the image as well as a "Delete" button. If this value is passed & set to true, the delete
 *          button will be removed (handy for "Review" pages).
 *     "delete_button_label" - by default, "Delete file"
 */
function ft_api_display_image_field($params)
{
  if (empty($params["field_name"]))
    return;

  $field_name = $params["field_name"];
  $namespace = isset($params["namespace"]) ? $params["namespace"] : "form_tools_form";

  // if an image hasn't already been uploaded through this field, do nothing
  if (!isset($_SESSION[$namespace][$field_name]) || !is_array($_SESSION[$namespace][$field_name]) ||
    !isset($_SESSION[$namespace][$field_name]["filename"]))
    return;

  $file_upload_url = $_SESSION[$namespace][$field_name]["file_upload_url"];
  $filename        = $_SESSION[$namespace][$field_name]["filename"];
  $width           = isset($params["width"]) ? "width=\"{$params["width"]}\" " : "";
  $height          = isset($params["height"]) ? "height=\"{$params["height"]}\" " : "";

  echo "<div><img src=\"$file_upload_url/$filename\" {$width}{$height}/></div>";

  // if required, add the "Delete"
  if (!isset($params["hide_delete_button"]) || !$params["hide_delete_button"])
  {
    $delete_file_label = (isset($params["delete_button_label"])) ? $params["delete_button_label"] : "Delete file";

    echo "<div><input type=\"submit\" name=\"form_tools_delete_image_field__$field_name\" value=\"$delete_file_label\" /></div>";
  }
}


/**
 * Just a wrapper function for ft_load_field - renamed for consistency with the API. Plus it's good
 * to draw attention to this function with the additional documentation.
 *
 * @param string $field_name
 * @param string $session_name
 * @param string $default_value
 */
function ft_api_load_field($field_name, $session_name, $default_value)
{
  return ft_load_field($field_name, $session_name, $default_value);
}


/**
 * This function lets you log a client or administrator in programmatically. By default, it logs the user in
 * and redirects them to whatever login page they specified in their user account. However, you can override
 * this in two ways: either specify a custom URL where they should be directed to, or avoid redirecting at
 * all. If you choose the latter, make sure you've initiated SESSIONS on the calling page - otherwise the
 * login account information (needed to be stored in sessions) is lost.
 *
 * @param array $info a hash with the following possible parameters:
 *     "username" - the username
 *     "password" - the password
 *     "auto_redirect_after_login" - (boolean, defaulted to false) determines whether or not the user should
 *         be automatically redirected to a URL after a successful login.
 *     "login_url" - the URL to redirect to (if desired). If this isn't set, but auto_redirect_after_login IS,
 *         it will log the user in normally, to whatever login page they've specified in their account.
 */
function ft_api_login($info)
{
  global $g_root_url, $g_table_prefix, $LANG, $g_api_debug;

  $username = ft_sanitize($info["username"]);
  $password = isset($info["password"]) ? ft_sanitize($info["password"]) : "";

  // extract info about this user's account
  $query = mysql_query("
    SELECT account_id, account_type, account_status, password, login_page
    FROM   {$g_table_prefix}accounts
    WHERE  username = '$username'
      ");
  $account_info = mysql_fetch_assoc($query);

  if (empty($password))
  {
     if ($g_api_debug)
     {
      $page_vars = array("message_type" => "error", "error_code" => 1000, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
     }
     else
       return array(false, 1000);
  }

  if (empty($account_info))
  {
     if ($g_api_debug)
     {
      $page_vars = array("message_type" => "error", "error_code" => 1004, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
     }
     else
       return array(false, 1004);
  }

  if ($account_info["account_status"] == "disabled")
  {
     if ($g_api_debug)
     {
      $page_vars = array("message_type" => "error", "error_code" => 1001, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
     }
     else
       return array(false, 1001);
  }

  if ($account_info["account_status"] == "pending")
  {
     if ($g_api_debug)
     {
      $page_vars = array("message_type" => "error", "error_code" => 1002, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
     }
     else
       return array(false, 1002);
  }

  if (md5(md5($password)) != $account_info["password"])
  {
     if ($g_api_debug)
     {
      $page_vars = array("message_type" => "error", "error_code" => 1003, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
     }
     else
       return array(false, 1003);
  }


  // all checks out. Log them in, after populating sessions
  $_SESSION["ft"]["settings"] = ft_get_settings("", "core"); // only load the core settings
  $_SESSION["ft"]["account"]  = ft_get_account_info($account_info["account_id"]);
  $_SESSION["ft"]["account"]["is_logged_in"] = true;
  $_SESSION["ft"]["account"]["password"] = md5(md5($password));

  ft_cache_account_menu($account_info["account_id"]);

  // if this is an administrator, build and cache the upgrade link and ensure the API version is up to date
  if ($account_info["account_type"] == "admin")
  {
    ft_update_api_version();
    ft_build_and_cache_upgrade_info();
  }

  // for clients, store the forms & form Views that they are allowed to access
  if ($account_info["account_type"] == "client")
    $_SESSION["ft"]["permissions"] = ft_get_client_form_views($account_info["account_id"]);


  // redirect the user to whatever login page they specified in their settings
  if (isset($info["auto_redirect_after_login"]) && $info["auto_redirect_after_login"])
  {
    if (isset($info["login_url"]) && !empty($info["login_url"]))
    {
      session_write_close();
      header("Location: $login_url");
      exit;
    }
    else
    {
      $login_url = ft_construct_page_url($account_info["login_page"]);
      $login_url = "$g_root_url{$login_url}";

      session_write_close();
      header("Location: $login_url");
      exit;
    }
  }

  return array(true, "");
}


/**
 * Creates a client account in the database.
 *
 * @param array $account_info this has has 4 required keys: first_name, last_name, user_name, password
 *
 * The password is automatically encrypted by this function.
 *
 * It also accepts the following optional keys:
 *   account_status: "active", "disabled", "pending"
 *   ui_language: (should only be one of the languages currently supported by the script, e.g. "en_us")
 *   timezone_offset: +- an integer value, for each hour
 *   sessions_timeout:
 *   date_format:
 *   login_page:
 *   logout_url:
 *   theme:
 *   menu_id:
 *
 * @return array [0] true / false
 *               [1] an array of error codes (if false) or the new account ID
 */
function ft_api_create_client_account($account_info)
{
  global $g_api_debug, $g_table_prefix;

  $account_info = ft_sanitize($account_info);

  $error_codes = array();

  // check all the valid fields
  if (!isset($account_info["first_name"]) || empty($account_info["first_name"]))
    $error_codes[] = 700;
  if (!isset($account_info["last_name"]) || empty($account_info["last_name"]))
    $error_codes[] = 701;
  if (!isset($account_info["email"]) || empty($account_info["email"]))
    $error_codes[] = 702;
  if (!ft_is_valid_email($account_info["email"]))
    $error_codes[] = 703;

  if (!isset($account_info["username"]) || empty($account_info["username"]))
    $error_codes[] = 704;
  else
  {
    if (preg_match('/[^A-Za-z0-9]/', $account_info["username"]))
      $error_codes[] = 705;
    if (!_ft_is_valid_username($account_info["username"]))
      $error_codes[] = 706;
  }

  if (!isset($account_info["password"]) || empty($account_info["password"]))
    $error_codes[] = 707;
  else
  {
    if (preg_match('/[^A-Za-z0-9]/', $account_info["password"]))
      $error_codes[] = 708;
  }

  if (!empty($error_codes))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_codes" => $error_codes);
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, $error_codes);
  }


  $first_name = $account_info["first_name"];
  $last_name  = $account_info["last_name"];
  $email      = $account_info["email"];
  $username   = $account_info["username"];
  $password   = md5(md5($account_info["password"]));

  $settings = ft_get_settings();
  $account_status   = (isset($account_info["account_status"])) ? $account_info["account_status"] : "pending";
  $language         = (isset($account_info["ui_language"])) ? $account_info["ui_language"] : $settings["default_language"];
  $timezone_offset  = (isset($account_info["timezone_offset"])) ? $account_info["timezone_offset"] : $settings["default_timezone_offset"];
  $sessions_timeout = (isset($account_info["sessions_timeout"])) ? $account_info["sessions_timeout"] : $settings["default_sessions_timeout"];
  $date_format      = (isset($account_info["date_format"])) ? $account_info["date_format"] : $settings["default_date_format"];
  $login_page       = (isset($account_info["login_page"])) ? $account_info["login_page"] : $settings["default_login_page"];
  $logout_url       = (isset($account_info["logout_url"])) ? $account_info["logout_url"] : $settings["default_logout_url"];
  $theme            = (isset($account_info["theme"])) ? $account_info["theme"] : $settings["default_theme"];
  $menu_id          = (isset($account_info["menu_id"])) ? $account_info["menu_id"] : $settings["default_client_menu_id"];

  // first, insert the record into the accounts table. This contains all the settings common to ALL
  // accounts (including the administrator and any other future account types)
  $query = "
     INSERT INTO {$g_table_prefix}accounts (account_type, account_status, ui_language, timezone_offset, sessions_timeout,
       date_format, login_page, logout_url, theme, menu_id, first_name, last_name, email, username, password)
     VALUES ('client', '$account_status', '$language', '$timezone_offset', '$sessions_timeout',
       '$date_format', '$login_page', '$logout_url', '$theme', $menu_id, '$first_name', '$last_name', '$email',
       '$username', '$password')
         ";

  if (!mysql_query($query))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 709, "error_type" => "user",
        "debugging" => "Failed query in <b>" . __FUNCTION__ . "</b>: <i>$query</i> " . mysql_error());
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, $error_codes);
  }

  $new_user_id = mysql_insert_id();


  // now create all the custom client account settings, most of which are based on the default values
  // in the settings table
  $account_settings = array(
    "client_notes" => "",
    "company_name" => "",
    "page_titles"          => $settings["default_page_titles"],
    "footer_text"          => $settings["default_footer_text"],
    "may_edit_page_titles" => $settings["clients_may_edit_page_titles"],
    "may_edit_footer_text" => $settings["clients_may_edit_footer_text"],
    "may_edit_theme"       => $settings["clients_may_edit_theme"],
    "may_edit_logout_url"  => $settings["clients_may_edit_logout_url"],
    "may_edit_language"    => $settings["clients_may_edit_ui_language"],
    "may_edit_timezone_offset"  => $settings["clients_may_edit_timezone_offset"],
    "may_edit_sessions_timeout" => $settings["clients_may_edit_sessions_timeout"],
    "may_edit_date_format"      => $settings["clients_may_edit_date_format"]
  );
  ft_set_account_settings($new_user_id, $account_settings);

  return array(true, $new_user_id);
}


/**
 * Updates a client account with whatever values are in $info.
 *
 * @param integer $account_id
 * @param array $info an array of keys to update, corresponding to the columns in the accounts table.
 */
function ft_api_update_client_account($account_id, $info)
{
  global $g_table_prefix, $g_api_debug;

  // check the account ID is valid
  $account_id = ft_sanitize($account_id);
  $info = ft_sanitize($info);
  $account_info = ft_get_account_info($account_id);

  // check the account ID was valid (i.e. the account exists) and that it's a CLIENT account
  if (!isset($account_info["account_id"]))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 900, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 900);
  }
  if ($account_info["account_type"] != "client")
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 901, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 901);
  }

  // get a list of the possible DB columns that can be updated
  $valid_columns = array("account_status", "ui_language", "timezone_offset", "sessions_timeout", "date_format",
    "login_page", "logout_url", "theme", "menu_id", "first_name", "last_name", "email", "username", "password");

  $mysql_update_rows = array();
  while (list($key, $value) = each($info))
  {
    // if something passed by the user isn't a valid column name, ignore it
    if (!in_array($key, $valid_columns))
      continue;

    // if this is the password field, encrypt it!
    if ($key == "password")
      $value = md5(md5($value));

    $mysql_update_rows[] = "$key = '$value'";
  }

  if (empty($mysql_update_rows))
    return array(true, "");

  $update_lines = "SET " . join(",\n", $mysql_update_rows);

  $query = "
    UPDATE {$g_table_prefix}accounts
    $update_lines
    WHERE account_id = $account_id
      ";

  $result = mysql_query($query);

  if ($result)
    return array(true, "");
  else
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 902, "error_type" => "user",
          "debugging"=> "Failed query in <b>" . __FUNCTION__ . ", " . __FILE__ . "</b>, line " . __LINE__ .
              ": <i>" . nl2br($query) . "</i><br /> " .  mysql_error());
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 902);
  }
}


/**
 * Completely deletes a client account from the database.
 *
 * @param integer $account_id
 * @return mixed
 *        if success:
 *            returns array with two indexes: [0] true, [1] empty string
 *
 *        if error:
 *            if $g_api_debug == true
 *               the error page will be displayed displaying the error code.
 *
 *            if $g_api_debug == false, it returns an array with two indexes:
 *                   [0] false
 *                   [1] the API error code
 */
function ft_api_delete_client_account($account_id)
{
  global $g_api_debug;

  $account_id = ft_sanitize($account_id);
  $account_info = ft_get_account_info($account_id);

  // check the account ID was valid (i.e. the account exists) and that it's a CLIENT account
  if (!isset($account_info["account_id"]))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 800, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 800);
  }
  if ($account_info["account_type"] != "client")
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 801, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 801);
  }

  ft_delete_client($account_id);

  return array(true, "");
}


/**
 * Deletes all unfinalized submissions and any associated files that have been uploaded.
 *
 * @param boolean $delete_all this deletes ALL unfinalized submissions. False by default. Normally it just
 *    deletes all unfinalized submissions made 2 hours and older. This wards against accidentally deleting
 *    those submissions currently being put through.
 *
 * @return integer the number of unfinalized submissions that were just deleted
 */
function ft_api_delete_unfinalized_submissions($form_id, $delete_all = false)
{
  global $g_table_prefix, $g_api_debug;

  if (!ft_check_form_exists($form_id))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 650, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 650);
  }

  $time_clause = (!$delete_all) ? "AND DATE_ADD(submission_date, INTERVAL 2 HOUR) < curdate()" : "";
  $query = mysql_query("
    SELECT *
    FROM   {$g_table_prefix}form_{$form_id}
    WHERE  is_finalized = 'no'
    $time_clause
      ");

  if (mysql_num_rows($query) == 0)
    return 0;


  // find out which of this form are file fields
  $form_fields = ft_get_form_fields($form_id);

  $file_field_info = array(); // a hash of col_name => file upload dir
  foreach ($form_fields as $field_info)
  {
    if ($field_info["field_type"] == "file")
    {
      $field_id = $field_info["field_id"];
      $col_name = $field_info["col_name"];
      $extended_settings = ft_get_extended_field_settings($field_id);

      $file_field_info[$col_name] = $extended_settings["file_upload_dir"];
    }
  }


  // now delete the info
  while ($submission_info = mysql_fetch_assoc($query))
  {
    $submission_id = $submission_info["submission_id"];

    // delete any files associated with the submission
    while (list($col_name, $file_upload_dir) = each($file_field_info))
    {
      if (!empty($submission_info[$col_name]))
        @unlink("{$file_upload_dir}/{$submission_info[$col_name]}");
    }
    reset($file_field_info);

    mysql_query("DELETE FROM {$g_table_prefix}form_{$form_id} WHERE submission_id = $submission_id");
  }

  return mysql_num_rows($query);
}


/**
 * Displays a captcha in your form pages, using the recaptcha service (http://recaptcha.net). Requires
 * you to have set up an account with them for this current website.
 *
 * @return mixed generally, this function just displays the CAPTCHA in your page. But in case of error:
 *
 *        if $g_api_debug == true, the error page will be displayed displaying the error code.
 *        if $g_api_debug == false, it returns an array with two indexes:
 *                   [0] false
 *                   [1] the API error code
 */
function ft_api_display_captcha()
{
  global $g_api_debug, $g_api_recaptcha_public_key, $g_api_recaptcha_private_key, $g_api_recaptcha_error;

  $folder = dirname(__FILE__);
  require_once("$folder/recaptchalib.php");

  // check the two recaptcha keys have been defined
  if (empty($g_api_recaptcha_public_key) || empty($g_api_recaptcha_private_key))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 600, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 600);
  }

  echo recaptcha_get_html($g_api_recaptcha_public_key, $g_api_recaptcha_error);
}


/**
 * This function checks to see if a submission is unique - based on whatever criteria you require
 * for your test case.
 *
 * @param integer $form_id
 * @param array $criteria a hash of whatever criteria is need to denote uniqueness, where the key is the
 *   database column name and the value is the current value being tested. For instance, if you wanted to check
 *   that no-one has submitted a form with a particular email address, you could pass
 *   array("email" => "myemail@whatever.com) as the second parameter (where "email" is the database column name).
 * @param integer $current_submission_id if this value is set, the function ignores that submission when doing
 *   a comparison.
 */
function ft_api_check_submission_is_unique($form_id, $criteria, $current_submission_id = "")
{
  global $g_api_debug, $g_table_prefix;

  // confirm the form is valid
  if (!ft_check_form_exists($form_id))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 550, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 550);
  }

  if (!is_array($criteria))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 551, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 551);
  }

  $where_clauses = array();
  while (list($col_name, $value) = each($criteria))
  {
    if (empty($col_name))
    {
      if ($g_api_debug)
      {
        $page_vars = array("message_type" => "error", "error_code" => 552, "error_type" => "user");
        ft_display_page("error.tpl", $page_vars);
        exit;
      }
      else
        return array(false, 552);
    }

    $where_clauses[] = "$col_name = '" . ft_sanitize($value) . "'";
  }

  if (empty($where_clauses))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 553, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 553);
  }

  if (!empty($current_submission_id))
  {
    $where_clauses[] = "submission_id != $current_submission_id";
  }

  $where_clause = "WHERE " . join(" AND ", $where_clauses);

  $query = @mysql_query("
    SELECT count(*) as c
    FROM {$g_table_prefix}form_{$form_id}
    $where_clause
    ");

  if ($query)
    $result = mysql_fetch_assoc($query);
  else
  {
    $page_vars = array("message_type" => "error", "error_code" => 554, "error_type" => "user");
    ft_display_page("error.tpl", $page_vars);
    exit;
  }

  return $result["c"] == 0;
}


/**
 * Initiates sessions. The session type (database or PHP) depends on the $g_session_type var
 * defined in the users config.php (or default value in library.php);
 */
function ft_api_start_sessions()
{
  global $g_session_type, $g_session_save_path, $g_api_header_charset;

  if ($g_session_type == "database")
    $sess = new SessionManager();

  if (!empty($g_session_save_path))
    session_save_path($g_session_save_path);

  session_start();
  header("Cache-control: private");
  header("Content-Type: text/html; charset=$g_api_header_charset");
}


/**
 * This function was provided to allow POST form users to include a reCAPTCHA in their form. This is
 * used to display an error message in the event of a failed attempt.
 *
 * @param string $message the message to output if there was a problem with the CAPTCHA contents.
 */
function ft_api_display_post_form_captcha_error($message = "")
{
  if (!isset($_SESSION["form_tools_form_data"]))
    return;

  if (isset($_SESSION["form_tools_form_data"]["api_recaptcha_error"]) && !empty($_SESSION["form_tools_form_data"]["api_recaptcha_error"]))
  {
    if ($message)
      echo $message;
    else
      echo "Sorry, the CAPTCHA (image verification) was entered incorrectly. Please try again.";
  }

  $_SESSION["form_tools_form_data"]["api_recaptcha_error"] = "";
}


/**
 * Returns all information about a submission. N.B. Would have been nice to have made this just a
 * wrapper for ft_get_submission_info, but that function contains hooks. Need to revise all core
 * code to allow external calls to optionally avoid any hook calls.
 *
 * @param integer $form_id
 * @param integer $submission_id
 */
function ft_api_get_submission($form_id, $submission_id)
{
  global $g_table_prefix, $g_api_debug;

  // confirm the form is valid
  if (!ft_check_form_exists($form_id))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 405, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 405);
  }

  if (!is_numeric($submission_id))
  {
    if ($g_api_debug)
    {
      $page_vars = array("message_type" => "error", "error_code" => 406, "error_type" => "user");
      ft_display_page("error.tpl", $page_vars);
      exit;
    }
    else
      return array(false, 406);
  }

  // get the form submission info
  $submission_info = mysql_query("
     SELECT *
     FROM   {$g_table_prefix}form_{$form_id}
     WHERE  submission_id = $submission_id
              ");

  $submission = mysql_fetch_assoc($submission_info);

  return $submission;
}
