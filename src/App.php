<?php
namespace CurrikiTsugi;
use Tsugi\Core\LTIX;
use \Tsugi\Core\Result;
use \Tsugi\Util\U;
use \Tsugi\Util\LTIConstants;
use \Tsugi\Grades\GradeUtil;
use CurrikiTsugi\Interfaces\ControllerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class App
{
    public $controller;

    public function __construct(ControllerInterface $controller = null) {        
        $this->controller = $controller;
    }   
    
    public function bootstrap()
    {
        global $LTI, $CFG, $PDOX;

        if (!is_null($this->controller)) {
            global $path_info_parts;
            //execute controller instead LTI Launch - also set controller action
            if (isset($path_info_parts[1]) && method_exists($this->controller, $path_info_parts[1])) {
                call_user_func(array($this->controller, $path_info_parts[1]));
            } else {
                call_user_func(array($this->controller, 'index'));
            }
        } else {
            $LTI = LTIX::requireData();

            $course_id = ParamValidate::getKeyInCustomFields($_SESSION, 'course_id');
            $custom_email_id = ParamValidate::getKeyInCustomFields($_SESSION, 'person_email_primary');
            $custom_course_name = ParamValidate::getKeyInCustomFields($_SESSION, 'course_name');
            $custom_api_domain_url = ParamValidate::getKeyInCustomFields($_SESSION, 'api_domain_url');
            $custom_course_code = ParamValidate::getKeyInCustomFields($_SESSION, 'course_code');
            $custom_person_name_given = ParamValidate::getKeyInCustomFields($_SESSION, 'person_name_given');
            $custom_person_name_family = ParamValidate::getKeyInCustomFields($_SESSION, 'person_name_family');
            $issuer_client = $_SESSION['lti']['issuer_client'];

            $lti_version = $LTI->ltiRawParameter(LTIConstants::LTI_VERSION, false);
            if ($lti_version === LTIConstants::LTI_VERSION_1) {
                $custom_email_id = $LTI->ltiRawParameter(LTIConstants::LIS_PERSON_CONTACT_EMAIL_PRIMARY, false);
                $custom_person_name_given = $LTI->ltiRawParameter(LTIConstants::LIS_PERSON_NAME_FAMILY, false);
                $custom_person_name_family = $LTI->ltiRawParameter(LTIConstants::LIS_PERSON_NAME_GIVEN, false);
                $custom_course_id =  $LTI->ltiRawParameter(LTIConstants::CONTEXT_ID, false);
                $custom_course_code =  $LTI->ltiRawParameter(LTIConstants::CONTEXT_LABEL, false);
            }
            // $LTI->var_dump();
            // Obtain User ID
            $user_id = $LTI->user->id; //TSUGI member ID
            // Obtain User Email
            $user_email = $LTI->user->email ?: false; //Canvas User email
            if (!$user_email && !empty($custom_email_id)) {
                // Try to obtain it from the custom fields.
                $user_email = $custom_email_id;
            }
            // Obtain User role
            $is_learner = !$LTI->user->instructor;
            $tool_platform = ParamValidate::toolPlatformInfo($_SESSION);
            $playlist_id = ParamValidate::playlistInCustom($_SESSION) ?: ParamValidate::playlistInQueryString($_SESSION);
            $project_id = ParamValidate::projectInCustom($_SESSION) ?: ParamValidate::projectInQueryString($_SESSION);
            $activity_id = ParamValidate::activityInCustom($_SESSION) ?: ParamValidate::activityInQueryString($_SESSION);
            $is_summary = U::get($_GET, "is_summary");
            if ($project_id) {
                $lti_token_params = http_build_query($_SESSION['lti_post']);
                $project_studio_link = CURRIKI_STUDIO_HOST . "/project/preview2/$project_id";
                $redirect_to_studio_url = $project_studio_link . "?" . $lti_token_params;
                header("Location: $redirect_to_studio_url");
            } elseif ($playlist_id && !$activity_id) {
                $lti_token_params = http_build_query($_SESSION['lti_post']);
                $playlist_studio_link = CURRIKI_STUDIO_HOST . "/playlist/$playlist_id/preview/lti";
                $redirect_to_studio_url = $playlist_studio_link . "?" . $lti_token_params;
                header("Location: $redirect_to_studio_url");
            } elseif ($activity_id && $is_summary != 1) {
                // [start]===== Open Summary Page for Moodle ========
                $isMoodleSummaryPage = property_exists($_SESSION["tsugi_jwt"]->body->{"https://purl.imsglobal.org/spec/lti/claim/custom"}, 'is_summary')
                    && property_exists($_SESSION["tsugi_jwt"]->body->{"https://purl.imsglobal.org/spec/lti/claim/custom"}, 'student_id')
                    && $_SESSION["tsugi_jwt"]->body->{"https://purl.imsglobal.org/spec/lti/claim/tool_platform"}->product_family_code === "moodle";
                if ($isMoodleSummaryPage) {
                    $summaryLtiLink = $CFG->wwwroot . '/mod/curriki/?is_submission_review=true';
                    if (!U::get($_GET, "is_submission_review")) {
                        // Launch LTI Summary Page link
                        header("Location: " . U::add_url_parm($summaryLtiLink, 'PHPSESSID', session_id()));
                        exit(0);
                    } elseif (U::get($_GET, "is_submission_review")) {
                        // redirect to CurrikiStudio Summary page
                        $lti_data = $LTI->ltiParameterArray();
                        $student_id = $_SESSION["tsugi_jwt"]->body->{"https://purl.imsglobal.org/spec/lti/claim/custom"}->student_id;
                        $result_id = $lti_data['result_id'];

                        // $student_id = U::get($_GET, "student_id");
                        $sql = "SELECT * FROM lti_user WHERE subject_key = '{$student_id}' LIMIT 1";
                        $student_data = $PDOX->allRowsDie($sql);
                        if (count($student_data) > 0) {
                            $student_pk_id = $student_data[0]['user_id'];
                        }
                        $student_result = "SELECT * FROM lti_result WHERE user_id = {$student_pk_id} AND link_id = {$_SESSION['lti']['link_id']} ORDER BY created_at DESC LIMIT 1";
                        $student_result = $PDOX->allRowsDie($student_result);
                        $result_id_x = $student_result[0]['result_id'];
                        $is_submission_review = base64_encode("result_id={$result_id_x}&activity_id={$activity_id}&user_id={$student_pk_id}");

                        if (!empty($is_submission_review)) {
                            parse_str(base64_decode($is_submission_review), $submission_data);
                            $submission_data['referrer'] = $CFG->wwwroot;
                            $build_submission_request_data = http_build_query($submission_data);

                            // encode user information.
                            $lti_summary_info = base64_encode($build_submission_request_data);
                            $studio_lti_summary_link = CURRIKI_STUDIO_HOST . "/lti/summary?submission=$lti_summary_info";
                            // Redirect User to the login page.
                            header("Location: $studio_lti_summary_link");
                            exit(0);
                        }
                    }
                }
                // Check if the grade is being passedback
                $is_gradepassback = (int) U::get($_GET, "gpb");
                if ($is_gradepassback) {
                    $gradetosend = U::get($_GET, 'final_grade') * 1.0;
                    $scorestr = "Your score of " . sprintf("%.2f%%", $gradetosend * 100) . " has been saved.";

                    $lti_data = $LTI->ltiParameterArray();
                    if (isset($_SESSION['lti_post']['lti_version']) && $_SESSION['lti_post']['lti_version'] == "LTI-1p0") {
                        $grade_params = $lti_data;
                        $grade_params['note'] = "You've been graded.";
                        $grade_params['result_id'] = $lti_data['result_id'];
                    } else {
                        $grade_params['issuer_client'] = $lti_data['issuer_client'];
                        $grade_params['lti13_privkey'] = $lti_data['lti13_privkey'] ?: '';
                        $grade_params['lti13_lineitem'] = $lti_data['lti13_lineitem'];
                        $grade_params['lti13_token_url'] = $lti_data['lti13_token_url'];
                        $grade_params['lti13_token_audience'] = $lti_data['lti13_token_audience'];
                        $grade_params['lti13_pubkey'] = $lti_data['lti13_pubkey'] ?: '';
                        $grade_params['lti13_subject_key'] = $lti_data['subject_key'];
                        $grade_params['note'] = "You've been graded.";
                        $grade_params['result_id'] = $lti_data['result_id'];
                    }

                    // LTI Submission Review - Canvas' Score API implementation
                    //Submission review link
                    $review_data = [];
                    $review_data['result_id'] = $lti_data['result_id'];
                    $review_data['activity_id'] = $activity_id;
                    $review_data['user_id'] = $user_id;

                    $build_review_data = http_build_query($review_data);

                    // encode user information.
                    $lti_submission_info = base64_encode($build_review_data);
                    $extra = false;
                    if (isset($_SESSION['lti']['issuer_client'])) {
                        $grade_params['lti13_extra'] = [
                            'https://canvas.instructure.com/lti/submission' => [
                                "new_submission" => true,
                                "submission_type" => "basic_lti_launch",
                                "submission_data" => $CFG->wwwroot . '/mod/curriki/?submission=' . $lti_submission_info,
                                "submitted_at" => date(DATE_RFC3339_EXTENDED),
                            ]
                        ];
                    }

                    // Use LTIX to send the grade back to the LMS.
                    // if you don't know the data to send when creating the response
                    $response = new JsonResponse();
                    $debug_log = [];
                    $retval = $LTI->result->gradeSend($gradetosend, $grade_params, $debug_log);
                    $_SESSION['debug_log'] = $debug_log;
                    $output = '';
                    if ($retval === true) {
                        $response->setStatusCode(Response::HTTP_OK);

                        $response->setData(['success' => true, "message" => $scorestr]);
                        $_SESSION['success'] = $scorestr;
                        //$output = $scorestr;
                    } elseif (is_string($retval)) {
                        $_SESSION['error'] = "Grade not sent: " . $retval;
                        $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);

                        $response->setData(['errors' => ["Grade not sent: " . $retval]]);
                        $output = $_SESSION['error'];
                    } else {
                        // $output = "<pre>\n" . print_r($retval, true) . "</pre>\n";
                        $response->setStatusCode(Response::HTTP_ACCEPTED);
                        $response->setData(['errors' => ["Grade not sent."]]);
                    }

                    $response->send();
                    exit(0);
                }
                // get the result id
                $submission_id = $LTI->result->id;
                $lti_token_params = http_build_query($_SESSION['lti_post']);
                $activity_studio_link = CURRIKI_STUDIO_HOST . "/lti-tools/activity/$activity_id";
                $redirect_to_studio_url = $activity_studio_link . "?" . $lti_token_params;
                $custom_variable_array = array(
                    'user_id',
                    'tool_platform',
                    'is_learner',
                    'submission_id',
                    'course_id',
                    'custom_course_name',
                    'custom_api_domain_url',
                    'custom_course_code',
                    'custom_email_id',
                    'issuer_client',
                    'custom_person_name_given',
                    'custom_person_name_family'
                );
                foreach ($custom_variable_array as $extra_param) {
                    $redirect_to_studio_url .= '&' . $extra_param . '=' . urlencode($$extra_param);
                }
                $redirect_to_studio_url .= '&homepage=' . urlencode($CFG->wwwroot);
                $redirect_to_studio_url = addSession($redirect_to_studio_url);
                header("Location: $redirect_to_studio_url");
            } else {
                // LTI Submission Review - Canvas' Score API implementation

                $is_submission_review = U::get($_GET, "submission");
                if(!$is_submission_review)
                {
                    $student_id = U::get($_GET, "student_id");
                    $sql = "SELECT * FROM lti_user WHERE subject_key = '{$student_id}' LIMIT 1";
                    $student_data = $PDOX->allRowsDie($sql);
                    if (count($student_data) > 0) {
                        $student_pk_id = $student_data[0]['user_id'];
                    }
    
                    $student_result = "SELECT * FROM lti_result WHERE user_id = {$student_pk_id} AND link_id = {$_SESSION['lti']['link_id']} ORDER BY created_at DESC LIMIT 1";
                    $student_result = $PDOX->allRowsDie($student_result);
    
                    $result_id = $student_result[0]['result_id'];
                    $is_submission_review = base64_encode("result_id={$result_id}&activity_id={$activity_id}&user_id={$student_pk_id}");
                }

                if (!empty($is_submission_review)) {

                    parse_str(base64_decode($is_submission_review), $submission_data);
                    $submission_data['referrer'] = $CFG->wwwroot;
                    $build_submission_request_data = http_build_query($submission_data);

                    // encode user information.
                    $lti_summary_info = base64_encode($build_submission_request_data);
                    $studio_lti_summary_link = CURRIKI_STUDIO_HOST . "/lti/summary?submission=$lti_summary_info";

                    // Redirect User to the login page.
                    header("Location: $studio_lti_summary_link");
                    exit(0);
                }
                // Single Sign On LTI request
                // we should move this to a new 
                $lti_data = $LTI->ltiParameterArray();
                $request_data = [];
                foreach (['user_key', 'user_email', 'user_displayname'] as $param) {
                    $request_data[$param] = $lti_data[$param];
                }

                $first_name = $LTI->ltiRawParameter(LTIConstants::LIS_PERSON_NAME_FAMILY, false);
                $last_name = $LTI->ltiRawParameter(LTIConstants::LIS_PERSON_NAME_GIVEN, false);
                $person_sourcedid = $LTI->ltiRawParameter(LTIConstants::LIS_PERSON_SOURCEDID, false);
                $tool_platform = $LTI->ltiRawParameter(LTIConstants::TOOL_CONSUMER_INFO_PRODUCT_FAMILY_CODE, false);
                $tool_consumer_instance_name = $LTI->ltiRawParameter(LTIConstants::TOOL_CONSUMER_INSTANCE_NAME, false);
                $tool_consumer_instance_guid = $LTI->ltiRawParameter(LTIConstants::TOOL_CONSUMER_INSTANCE_GUID, false);
                $custom_school = $LTI->ltiRawParameter('custom_' . $tool_platform . '_schoolname', false);
                $oauth_consumer_key = $LTI->ltiRawParameter('oauth_consumer_key');
                $request_data['first_name'] = $first_name;
                $request_data['last_name'] = $last_name;
                $request_data['tool_platform'] = $tool_platform;
                $request_data['tool_consumer_instance_name'] = $tool_consumer_instance_name;
                $request_data['tool_consumer_instance_guid'] = $tool_consumer_instance_guid;
                $request_data['custom_' . $tool_platform . '_school'] = $custom_school;
                $request_data['oauth_consumer_key'] = $oauth_consumer_key;

                $build_request_data = http_build_query($request_data);

                // encode user information.
                $lti_user_info = base64_encode($build_request_data);
                $studio_login_link = CURRIKI_STUDIO_HOST . "/lti-sso?sso_info=$lti_user_info";

                // Redirect User to the login page.
                header("Location: $studio_login_link");
                exit(0);
            }
        }
    }
}
