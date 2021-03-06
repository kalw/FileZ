<?php
/**
 * Copyright 2010  Université d'Avignon et des Pays de Vaucluse 
 * email: gpl@univ-avignon.fr
 *
 * This file is part of Filez.
 *
 * Filez is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Filez is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Filez.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Controller used to do various actions on files (delete, email, download)
 */
class App_Controller_File extends Fz_Controller {

    /**
     * Display file info and open a download dialog
     */
    public function previewAction () {
        $file = $this->getFile();
        $isOwner = $file->isOwner ($this->getUser ());
        set ('file',            $file);
        set ('isOwner',         $isOwner);
        set ('available',       $file->isAvailable () || $isOwner);
        set ('checkPassword',   !(empty ($file->password) || $isOwner));
        set ('uploader',        $file->getUploader ());
        return html ('file/preview.php');
    }

    /**
     * Download a file
     */
    public function downloadAction () {
        $file = $this->getFile ();
        if (! $file->isOwner ($this->getUser ())) {
            if (! $file->isAvailable ()) {
                halt (HTTP_FORBIDDEN, __('File is not available for download'));
            } else if (! empty ($file->password)
                    && ! $file->checkPassword ($_POST['password'])) {
                flash ('error', __('Incorrect password'));
                redirect ('/'.$file->getHash());
            }
        }

        $file->download_count = $file->download_count + 1;
        $file->save ();
        return $this->sendFile ($file);
    }

    /**
     * Extend lifetime of a file
     */
    public function extendAction () {
        $file = $this->getFile ();

        $result = array ();
        if ($file->extends_count < fz_config_get ('app', 'max_extend_count')) {
            $file->extendLifetime ();
            $file->save ();
            $result ['status']     = 'success';
            $result ['statusText'] = __('Lifetime extended');
            $result ['html']       = partial ('main/_file_row.php', array ('file' => $file));
        } else {
            $result ['status']     = 'error';
            $result ['statusText'] = __r('You can\'t extend a file lifetime more than %x% times',
                                    array ('x' => fz_config_get ('app', 'max_extend_count')));
        }

        if ($this->isXhrRequest()) {
            return json ($result);
        }
        else {
            flash (($result ['status'] == 'success' ? 'notification' : 'error'),
                    $result ['statusText']);
            redirect_to ('/');
        }
    }

    /**
     * Allows to download file with filez-1.x urls
     */
    public function downloadFzOneAction () {
        if (! fz_config_get('app', 'filez1_compat'))
            halt (HTTP_FORBIDDEN);
        
        $file = Fz_Db::getTable('File')->findByFzOneHash ($_GET ['ad']);
        if ($file === null) {
            halt (NOT_FOUND, __('There is no file for this code'));
        }
        set ('file',      $file);
        set ('available', $file->isAvailable () || $file->isOwner ($this->getUser ()));
        set ('uploader',  $file->getUploader ());
        return html ('file/preview.php');
    }


    /**
     * Delete a file
     */
    public function confirmDeleteAction () {
        $this->secure ();
        $file = $this->getFile ();
        $user = $this->getUser ();
        $this->checkOwner ($file, $user);
        set ('file', $file);

        return html ('file/confirmDelete.php');
    }
    /**
     * Delete a file
     */
    public function deleteAction () {
        $this->secure ();
        $file = $this->getFile ();
        $user = $this->getUser ();
        $this->checkOwner ($file, $user);
        $file->delete();

        if ($this->isXhrRequest())
            return json (array ('status' => 'success'));
        else {
            flash ('notification', __('File deleted.'));
            redirect_to ('/');
        }
    }

    /**
     * Share a file url by mail (show email form only)
     */
    public function emailFormAction () {
        $this->secure ();
        $user = $this->getUser ();
        $file = $this->getFile ();
        $this->checkOwner ($file, $user);
        set ('file', $file);
        return html ('file/email.php');
    }

    /**
     * Share a file url by mail
     */
    public function emailAction () {
        $this->secure ();
        $user = $this->getUser ();
        $file = $this->getFile ();
        $this->checkOwner ($file, $user);
        set ('file', $file);

        // Send mails
        $user = $this->getUser ();
        $mail = $this->createMail();
        $subject = __r('[FileZ] "%sender%" wants to share a file with you', array (
            'sender' => $user['firstname'].' '.$user['lastname']));
        $msg = __r('email_share_file (%file_name%, %file_url%, %sender%, %msg%)', array(
            'file_name' => $file->file_name,
            'file_url'  => $file->getDownloadUrl(),
            'msg'       => $_POST ['msg'],
            'sender'    => $user['firstname'].' '.$user['lastname'],
        ));
        $mail->setBodyText ($msg);
        $mail->setSubject  ($subject);
        $mail->setReplyTo  ($user['email'],
                            $user['firstname'].' '.$user['lastname']);
        $mail->clearFrom();
        $mail->setFrom     ($user['email'],
                            $user['firstname'].' '.$user['lastname']);

        $emailValidator = new Zend_Validate_EmailAddress();
        foreach (explode (' ', $_POST['to']) as $email) {
            $email = trim ($email);
            if (empty ($email))
                continue;
            if ($emailValidator->isValid ($email))
                $mail->addBcc ($email);
            else {
                $msg = __r('Email address "%email%" is incorrect, please correct it.',
                    array ('email' => $email));
                return $this->returnError ($msg, 'file/email.php');
            }
        }

        try {
            $mail->send ();
            return $this->returnSuccessOrRedirect ('/');
        }
        catch (Exception $e) {
            fz_log ('Error while sending email', FZ_LOG_ERROR, $e);
            $msg = __('An error occured during email submission. Please try again.');
            return $this->returnError ($msg, 'file/email.php');
        }
    }

    // TODO documenter les 2 fonctions suivantes et ? les passer dans la classe controleur

    private function returnError ($msg, $template) {
        if ($this->isXhrRequest ()) {
            return json (array (
                'status' => 'error',
                'statusText' => $msg
            ));
        } else {
            flash_now ('error', $msg);
            return html ($template);
        }
    }
    private function returnSuccessOrRedirect ($url) {
        if ($this->isXhrRequest ()) {
            return json (array ('status' => 'success'));
        } else {
            redirect_to ($url);
        }
    }

    /**
     * Retrieve the requested file from database.
     * If the file isn't found, the action is stopped and a 404 error is returned.
     *
     * @return App_Model_File
     */
    protected function getFile () {
        $file = Fz_Db::getTable('File')->findByHash (params ('file_hash'));
        if ($file === null) {
            halt (NOT_FOUND, __('There is no file for this code'));
        }
        return $file;
    }

    /**
     * Send a file through the standart output
     * @param App_Model_File $file      File to send
     */
    protected function sendFile (App_Model_File $file) {
        $mime = file_mime_content_type ($file->getFileName ());
        header('Content-Type: '.$mime);
        header('Content-Disposition: attachment; filename="'.
            iconv ("UTF-8", "ISO-8859-1", $file->getFileName ()).'"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Pragma: public');
        header('Content-Length: '.$file->file_size);
        return file_read ($file->getOnDiskLocation ());
    }

    /**
     * Checks if the user is the owner of the file. Stop the request if not.
     * 
     * @param App_Model_File $file
     * @param array $user
     */
    protected function checkOwner (App_Model_File $file, $user) {        
        if ($file->isOwner ($user))
            return;

        halt (HTTP_UNAUTHORIZED, __('You are not the owner of the file'));
    }


}

?>
