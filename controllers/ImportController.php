<?php

/**
 * Class file.
 *
 * @author Tobias Munk <schmunk@usrbin.de>
 * @link http://www.phundament.com/
 * @copyright Copyright &copy; 2005-2011 diemeisterei GmbH
 * @license http://www.phundament.com/license/
 */

Yii::import('jquery-file-upload-widget.*'); // Upload Handler

/**
 * Controller handling file import
 *
 * Detail description
 *
 * @author Tobias Munk <schmunk@usrbin.de>
 * @package p3media.controllers
 * @since 3.0.1
 */
class ImportController extends Controller
{

    public function filters()
    {
        return array(
            'accessControl',
        );
    }

    public function accessRules()
    {
        return array(
            array(
                'allow',
                'actions' => array('upload', 'uploadPopup', 'uploadFile', 'ckeditorUpload', 'sirTrevorUpload'),
                'expression' => 'Yii::app()->user->checkAccess("P3media.Import.*")',
            ),
            array(
                'allow',
                'actions' => array('check', 'localFile', 'scan'),
                'expression' => 'Yii::app()->user->checkAccess("P3media.Import.scan")',
            ),
            array(
                'deny',
                'users' => array('*'),
            ),
        );
    }

    public $breadcrumbs = array(
        'p3media' => array('/p3media')
    );

    public function init()
    {
        parent::init();
        #$this->module->getDataPath(true);
    }

    public function actionUpload()
    {
        $this->render('upload');
    }

    public function actionUploadPopup()
    {
        $this->layout = "//layouts/popup";
        $this->render('upload');
    }

    /**
     * Upload handler action to handle $_FILES contents sent by jQuery File Upload
     * @return array|mixed
     * @throws CException
     */
    public function actionUploadFile()
    {
        $contents = $this->uploadHandler();
        echo CJSON::encode($contents);
        exit;
    }

    /**
     * Upload handler helper method to handle $_FILES contents sent by jQuery File Upload
     * @return array|mixed
     * @throws CException
     */
    protected function uploadHandler()
    {
        #$script_dir = Yii::app()->basePath.'/data/p3media';
        #$script_dir_url = Yii::app()->baseUrl;
        $options = array(
            'url' => $this->createUrl("/p3media/p3Media/update", array('path' => Yii::app()->user->id . "/")),
            'upload_dir' => $this->module->getDataPath() . DIRECTORY_SEPARATOR,
            'upload_url' => $this->createUrl(
                    "/p3media/p3Media/update",
                    array('preset' => 'raw', 'path' => Yii::app()->user->id . "/")
                ),
            'script_url' => $this->createUrl("/p3media/import/uploadFile", array('path' => Yii::app()->user->id . "/")),
            'field_name' => 'files',
            'image_versions' => array(
                // Uncomment the following version to restrict the size of
                // uploaded images. You can also add additional versions with
                // their own upload directories:
                /*
                  'large' => array(
                  'upload_dir' => dirname(__FILE__).'/files/',
                  'upload_url' => dirname($_SERVER['PHP_SELF']).'/files/',
                  'max_width' => 1920,
                  'max_height' => 1200
                  ),
                 */
                'thumbnail' => array(
                    #'upload_dir' => dirname(__FILE__).'/thumbnails/',
                    'upload_url' => $this->createUrl(
                            "/p3media/file/image",
                            array('preset' => 'p3media-upload', 'path' => urlencode(Yii::app()->user->id . "/"))
                        ),
                    'max_width' => 80,
                    'max_height' => 80
                )
            )
        );

        // wrapper for jQuery-file-upload/upload.php
        $upload_handler = new UploadHandler($options);
        header('Pragma: no-cache');
        header('Cache-Control: private, no-cache');
        header('Content-Disposition: inline; filename="files.json"');
        header('X-Content-Type-Options: nosniff');

        ob_start();
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'HEAD':
            case 'GET':
                //$upload_handler->get();
                //$upload_handler_output = ob_get_contents();
                //$contents = CJSON::decode($upload_handler_output);
                $contents = array(); // we do not show existing files, since this list may get very long
                header('HTTP/1.0 405 Method Not Allowed');
                break;
            case 'POST':
                // check if file exists
                $upload = $_FILES[$options['field_name']];
                $tmp_name = $_FILES[$options['field_name']]['tmp_name'];
                if (is_array($tmp_name)) {
                    foreach ($tmp_name AS $index => $value) {
                        $model = P3Media::model()->findByAttributes(
                            array('path' => Yii::app()->user->id . DIRECTORY_SEPARATOR . $upload['name'][$index])
                        );
                        $model = new P3Media;

                        $attributes['path'] = Yii::app()->user->id . DIRECTORY_SEPARATOR . $upload['name'][$index];
                        #$attributes['title'] = $upload['name'][$index]; // TODO: fix title unique check
                        #var_dump($attributes['title']);exit;

                        $model->attributes = $attributes;
                        $model->validate(array('path'));

                        if ($model->hasErrors()) {
                            #throw new CHttpException(500, 'File exists.');
                            $file = new stdClass();
                            $file->error = "";
                            foreach ($model->getErrors() AS $error) {
                                $file->error .= $error[0];
                            }
                            $info[] = $file;
                            return $info;
                        }
                    }
                }

                $upload_handler->post();
                $upload_handler_output = ob_get_contents();
                $result = CJSON::decode($upload_handler_output);
                #var_dump($result);exit;
                $savedMedia = $this->createMedia(
                    $result[0]['name'],
                    $this->module->getDataPath() . DIRECTORY_SEPARATOR . $result[0]['name']
                );
                $result[0]['p3_media_id'] = $savedMedia->id;
                $result[0]['p3_media'] = $savedMedia->attributes;
                $contents = $result;
                break;
            case 'DELETE':
                $upload_handler->delete();
                $upload_handler_output = ob_get_contents();
                $contents = CJSON::decode($upload_handler_output);
                $result = $this->deleteMedia($_GET['path']);
                if (!$result) {
                    throw new CException("Delete media failed");
                }
                break;
            default:
                header('HTTP/1.0 405 Method Not Allowed');
                $upload_handler_output = ob_get_contents();
                $contents = CJSON::decode($upload_handler_output);
        }
        ob_end_clean();

        return $contents;
    }

    public function actionScan()
    {
        $dir = Yii::getPathOfAlias($this->module->importAlias);
        $files = array();
        foreach (scandir($dir, 0) AS $fileName) {
            $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;
            if (substr($fileName, 0, 1) == ".") {
                continue;
            }
            if (is_dir($filePath)) {
                continue;
            }
            #$md5 = md5_file($filePath);
            $files[] = new CAttributeCollection(array(
                'name' => $fileName,
                'path' => $filePath,
                'id' => md5($fileName),
            ));
        }
        $this->render('scan', array('files' => $files));
    }

    public function actionCheck()
    {
        $warnings = null;
        $result = null;
        $message = null;

        $fileName = $_GET['fileName'];

        $filePath = realpath(Yii::getPathOfAlias($this->module->importAlias) . DIRECTORY_SEPARATOR . $fileName);

        if (is_file($filePath) && strstr($filePath, realpath(Yii::getPathOfAlias($this->module->importAlias)))) {
            $md5 = md5_file($filePath);
            $result['md5'] = $md5;
            if (P3Media::model()->findByAttributes(array('hash' => $md5)) !== null) {
                $message .= $warnings[] = "Identical file exists";
                $message .= "<br/>";
            }
            if (P3Media::model()->findByAttributes(array('original_name' => $fileName)) !== null) {
                $message .= $warnings[] = "File with same name exists";
                $message .= "<br/>";
            }

            if (count($warnings) == 0) {
                $message .= "OK";
            }
        } else {
            $warnings[] = "File not found";
        }

        $result['errors'] = $warnings;
        $result['message'] = $message;
        echo CJSON::encode($result);
    }

    public function actionLocalFile()
    {

        if ($this->module->resolveFilePath($_GET['fileName'])) {

            $fileName = $_GET['fileName'];
            $importFilePath = $this->module->resolveFilePath($_GET['fileName']);

            $dataFilePath = $this->module->getDataPath() . DIRECTORY_SEPARATOR . $fileName;
            copy($importFilePath, $dataFilePath);

            $model = $this->createMedia($fileName, $dataFilePath);
            echo CJSON::encode($model->attributes);
        } else {
            throw new CHttpException(500, 'File not found');
        }
    }

    public function actionCkeditorUpload()
    {
        if ($_FILES['upload']['tmp_name']) {

            $fileName = $_FILES['upload']['name'];
            $importFilePath = $_FILES['upload']['tmp_name'];

            $dataFilePath = $this->module->getDataPath() . DIRECTORY_SEPARATOR . $fileName;
            copy($importFilePath, $dataFilePath);

            $model = $this->createMedia($fileName, $dataFilePath);

            echo "<script type='text/javascript'>
window.parent.CKEDITOR.tools.callFunction(" . $_GET['CKEditorFuncNum'] . ", '" . $model->createUrl('large') . "', '');
        </script>";
        } else {
            throw new CHttpException(500, 'File not found');
        }
    }

    /**
     * Action for file uploads via sir-trevor image block from SirTrevorWidget (input widget)
     * Making it compatible with http://www.yiiframework.com/extension/yii2-sir-trevor-js/
     *
     * @param $root relative base folder
     * @return array|mixed
     * @throws CException
     */
    public function actionSirTrevorUpload()
    {

        $response = new stdClass();
        $response->message = "File";

        if ($_FILES['attachment']['tmp_name']) {

            $fileName = $_FILES['attachment']['name']['file'];
            $importFilePath = $_FILES['attachment']['tmp_name']['file'];

            $dataFilePath = $this->module->getDataPath() . DIRECTORY_SEPARATOR . $fileName;
            if (copy($importFilePath, $dataFilePath)) {

                /* @var P3Media $model */
                $model = $this->createMedia($fileName, $dataFilePath);

                if ($model) {
                    $response->file = array(
                        'url' => $model->createUrl("original-public", true),
                        'p3_media_id' => $model->id,
                    );
                    header("HTTP/1.1 200 OK");
                } else {
                    header("HTTP/1.1 500 Database record could not be saved.");
                }

            } else {
                header("HTTP/1.1 500 File could not be saved.");
            }

        } else {
            header("HTTP/1.1 500 No temporary file found after upload.");
        }

        echo CJSON::encode($response);
        exit;

    }

    protected function createMedia($fileName, $fullFilePath)
    {
        $filePath = str_replace(Yii::getPathOfAlias($this->module->dataAlias) . DIRECTORY_SEPARATOR, "", $fullFilePath);

        $md5 = md5_file($fullFilePath);
        $getimagesize = getimagesize($fullFilePath);

        $model = new P3Media;
        $model->detachBehavior('Upload');

        $model->default_title = $fileName;
        $model->original_name = $fileName;

        $model->type = P3Media::TYPE_FILE;
        $model->path = $filePath;
        $model->hash = $md5;

        $model->access_domain = '*';

        if (function_exists("mime_content_type")) {
            $mime = mime_content_type($fullFilePath);
        } else {
            if (function_exists("finfo_open")) {
                $finfo = finfo_open($fullFilePath);
                $m = finfo_file($finfo, $filename);
                finfo_close($finfo);
            } else {
                $mime = $getimagesize['mime'];
            }
        }
        $model->mime_type = $mime;
        $model->info_php_json = CJSON::encode(getimagesize($fullFilePath));
        $model->size = filesize($fullFilePath);

        if ($model->save()) {
            return $model;
        } else {
            $errorMessage = "";
            foreach ($model->errors AS $attrErrors) {
                $errorMessage .= implode(',', $attrErrors);
            }
            throw new CHttpException(500, $errorMessage);
        }
    }

    protected function deleteMedia($filePath)
    {
        $attributes['path'] = $filePath;
        $model = P3Media::model()->findByAttributes($attributes);
        #unlink($this->getDataPath(true) . DIRECTORY_SEPARATOR . $fileName);
        $model->delete();
        return true;
    }

    protected function findMd5($md5)
    {
        $model = P3Media::model()->findByAttributes(array('hash' => $md5));
        if ($model === null) {
            return false;
        } else {
            return true;
        }
    }

    // -----------------------------------------------------------
    // Uncomment the following methods and override them if needed
    /*
      public function filters()
      {
      // return the filter configuration for this controller, e.g.:
      return array(
      'inlineFilterName',
      array(
      'class'=>'path.to.FilterClass',
      'propertyName'=>'propertyValue',
      ),
      );
      }

      public function actions()
      {
      // return external action classes, e.g.:
      return array(
      'action1'=>'path.to.ActionClass',
      'action2'=>array(
      'class'=>'path.to.AnotherActionClass',
      'propertyName'=>'propertyValue',
      ),
      );
      }
     */
}