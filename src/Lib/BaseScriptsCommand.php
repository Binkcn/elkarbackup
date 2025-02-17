<?php
namespace App\Lib;

use App\Entity\Client;
use App\Entity\Job;


abstract class BaseScriptsCommand extends LoggingCommand
{
    /**
     * Compare funtion for post scripts so that they get sorted by name desc
     * @param Script $a
     * @param Script $b
     * @return int
     */
    public static function comparePostScripts($a, $b) 
    {
        return strcmp($b->getName(), $a->getName());
    }
    /**
     * Compare funtion for pre scripts so that they get sorted by name asc
     * @param Script $b
     * @param Script $a
     * @return int
     */
    public static function comparePreScripts($a, $b) 
    {
        return strcmp($a->getName(), $b->getName());
    }
    /**
     * Prepares the model with the necessary data to run Client scripts.
     *
     * @param   Client      $client     Client entity.
     *
     * @param   string      $type       Defines the type of scripts the model will be used for, PRE or POST.
     *
     * @return array        $model      The model needed to execute scripts.
     */
    protected function prepareClientModel($client, $type)
    {
        $model = array();
        
        $model['level']             = 'CLIENT';
        $model['type']              = $type; //must be PRE or POST
        $model['clientUrl']         = $client->getUrl();
        $model['clientId']          = $client->getId();
        $model['status']            = self::ERR_CODE_OK; //status from the previous command
        $model['clientName']        = $client->getName();
        $model['clientDiskUsage']   = $client->getDiskUsage();
        $model['clientSshArgs']     = $client->getSshArgs();
        $model['scriptFiles']       = array();
        $model['context']           = array('link' => $this->generateClientRoute($client->getId()));
        
        if ( self::TYPE_PRE == $type){
            $model['clientEndTime'] = 0;
            $time = time();
            $model['clientStartTime'] = $time;
            $data = array();
            $data['clientStartTime'] = $time;
            $client->setData($data);
            $scripts = $client->getPreScripts();
            foreach ($scripts as $script) {
                array_push($model['scriptFiles'], $script);
            }
            usort($model['scriptFiles'], array('App\Lib\BaseScriptsCommand', 'comparePreScripts'));
        } elseif (self::TYPE_POST == $type) {
            $data = $client->getData();
            if (null != $data) {
                $model['clientStartTime'] = $data['clientStartTime'];
            } else {
                $model['clientStartTime'] = '';
            }
            
            $model['clientEndTime'] = time();
            $scripts = $client->getPostScripts();
            foreach ($scripts as $script) {
                array_push($model['scriptFiles'], $script);
            }
            usort($model['scriptFiles'], array('App\Lib\BaseScriptsCommand', 'comparePostScripts'));
            $client->setData(null);
        }
        
        return $model;
    }
    
    /**
     * Runs client level scripts
     *
     * @param   array       $model      Contains the pertinent information to run the scripts
     */
    protected function runClientScripts($model)
    {
        $errScriptError   = 'Client "%entityid%" %scripttype% script "%scriptname%" execution failed. Diagnostic information follows: %output%';
        $errScriptMissing = 'Client "%entityid%" %scripttype% script "%scriptname%" present but file "%scriptfile%" missing.';
        $errScriptOk      = 'Client "%entityid%" %scripttype% script "%scriptname%" execution succeeded. Output follows: %output%';
        $commandOutput = array();
        $clientId = $model['clientId'];
        $context = array('link' => $this->generateClientRoute($clientId));
        
        if (null == $model['scriptFiles']) {
            $this->info(
                'There are no ' . $model['type'] . ' scripts to run for this client.',
                array(),
                $context
            );
            return self::ERR_CODE_OK;
        }
        
        foreach ($model['scriptFiles'] as $script) {
            $scriptFile = $script->getScriptPath();
            if (!file_exists($scriptFile)) {
                $this->err(
                    $errScriptMissing,
                    array(
                        '%entityid%'   => $model['clientId'],
                        '%scripttype%' => $model['type'],
                        '%scriptname%' => $script->getName(),
                        '%scriptfile%' => $scriptFile
                    ),
                    $model['context']
                );
                return self::ERR_CODE_NOT_FOUND;
            } else {
                $command = sprintf('env ELKARBACKUP_LEVEL="%s" ELKARBACKUP_EVENT="%s" ELKARBACKUP_URL="%s" ELKARBACKUP_ID="%s" ELKARBACKUP_STATUS="%s" ELKARBACKUP_CLIENT_NAME="%s" ELKARBACKUP_CLIENT_TOTAL_SIZE="%s" ELKARBACKUP_CLIENT_STARTTIME="%s" ELKARBACKUP_CLIENT_ENDTIME="%s" ELKARBACKUP_SSH_ARGS="%s" "%s" 2>&1',
                    $model['level'],
                    $model['type'],
                    $model['clientUrl'],
                    $model['clientId'],
                    $model['status'],
                    $model['clientName'],
                    $model['clientDiskUsage'],
                    $model['clientStartTime'],
                    $model['clientEndTime'],
                    $model['clientSshArgs'],
                    $scriptFile);
                exec($command, $commandOutput, $status);
                
                foreach($commandOutput as $key => $line) {
                    $find_r = strrpos($line, "\r");
                    if (false !== $find_r){
                        $commandOutput[$key] = substr($line, $find_r+1);
                    }
                }
                
                $commandOutputString = substr("\n" . implode("\n", $commandOutput), 0, 500); // Let's limit the output
                if (self::ERR_CODE_OK != $status) {
                    $this->err(
                        $errScriptError,
                        array(
                            '%entityid%'   => $model['clientId'],
                            '%scripttype%' => $model['type'],
                            '%scriptname%' => $script->getName(),
                            '%output%'     => $commandOutputString
                        ),
                        $model['context']
                        );
                    return self::ERR_CODE_PROC_EXEC_FAILURE;
                } else {
                    $this->info(
                        $errScriptOk,
                        array(
                            '%entityid%'   => $model['clientId'],
                            '%scripttype%' => $model['type'],
                            '%scriptname%' => $script->getName(),
                            '%output%'     => $commandOutputString
                        ),
                        $model['context']
                    );
                }
            }
        }
        return self::ERR_CODE_OK;
    }
    
    /**
     * Prepares the model with the necessary data to run Job scripts.
     *
     * @param   Job         $job        Job entity.
     *
     * @param   string      $type       Defines the type of scripts the model will be used for, PRE or POST.
     * 
     * @param   string      $status     The status of the last execution;
     *
     * @return  array       $model      The model needed to execute scripts.
     */
    protected function prepareJobModel($job, $type, $status = self::ERR_CODE_OK)
    {
        $model = array();
        $client = $job->getClient();
        $container = $this->getContainer();
        
        $model['level']             = 'JOB';
        $model['type']              = $type; //must be PRE or POST
        $model['clientUrl']         = $job->getUrl();
        $model['clientId']          = $client->getId();
        $model['jobRoot']           = $job->getSnapshotRoot();
        $model['clientName']        = $client->getName();
        $model['jobName']           = $job->getName();
        $model['jobId']             = $job->getId();
        $model['ownerEmail']        = $client->getOwner()->getEmail();
        $model['recipientList']     = $job->getNotificationsEmail();
        $model['clientDiskUsage']   = $client->getDiskUsage();
        $model['jobTotalSize']      = $job->getDiskUsage();
        $model['clientSshArgs']     = $client->getSshArgs();
        $model['scriptFiles']       = array();
        $model['context']           = array('link' => $this->generateJobRoute($job->getId(), $job->getClient()->getId()));
        
        if (self::TYPE_PRE == $type){
            $scripts = $job->getPreScripts();
            foreach ($scripts as $script) {
                array_push($model['scriptFiles'], $script);
            }
            usort($model['scriptFiles'], array('App\Lib\BaseScriptsCommand', 'comparePreScripts'));
            $model['jobRunSize']    = 0;
            $model['jobStartTime']  = time();
            $model['jobEndTime']    = 0;
            $model['status']        = self::ERR_CODE_OK;
        } elseif (self::TYPE_POST == $type) {
            $model['status']        = $status;
            $scripts = $job->getPostScripts();
            foreach ($scripts as $script) {
                array_push($model['scriptFiles'], $script);
            }
            usort($model['scriptFiles'], array('App\Lib\BaseScriptsCommand', 'comparePostScripts'));
            
            $queue = $container
            ->get('doctrine')
            ->getRepository('App:Queue')
            ->findOneBy(array('job' => $job));
            if (null != $queue) {
                $data = $queue->getData();
            }
            
            if (null == $queue || null == $data) {
                $model['jobRunSize']    = '';
                $model['jobStartTime']  = '';
                $model['jobEndTime']    = '';
                
            } else {
                $model['jobRunSize']    = $data['ELKARBACKUP_JOB_RUN_SIZE'];
                $model['jobStartTime']  = $data['ELKARBACKUP_JOB_STARTTIME'];
                $model['jobEndTime']    = $data['ELKARBACKUP_JOB_ENDTIME'];
            }
        }
        
        return $model;
    }
    
    /**
     * Runs job level scripts
     *
     * @param   array       $model      Contains the pertinent information to run the scripts
     */
    protected function runJobScripts($model)
    {
        $errScriptError   = 'Job "%entityid%" %scripttype% script "%scriptname%" execution failed. Diagnostic information follows: %output%';
        $errScriptMissing = 'Job "%entityid%" %scripttype% script "%scriptname%" present but file "%scriptfile%" missing.';
        $errScriptOk      = 'Job "%entityid%" %scripttype% script "%scriptname%" execution succeeded. Output follows: %output%';
        $allOk = true;
        $commandOutput = array();
        
        $clientId = $model['clientId'];
        $jobId = $model['jobId'];
        $context = array('link' => $this->generateJobRoute($jobId, $clientId));
        
        if (null == $model['scriptFiles']) {
            $this->info(
                'There are no ' . $model['type'] . ' scripts to run for this job.',
                array(),
                $context
                );
            return self::ERR_CODE_OK;
        }
        
        foreach ($model['scriptFiles'] as $script) {
            $scriptFile = $script->getScriptPath();
            if (!file_exists($scriptFile)) {
                $this->err(
                    $errScriptMissing,
                    array(
                        '%entityid%'   => $model['jobId'],
                        '%scripttype%' => $model['type'],
                        '%scriptname%' => $script->getName(),
                        '%scriptfile%' => $scriptFile
                    ),
                    $model['context']
                );
                return self::ERR_CODE_NOT_FOUND;
            } else {
                $command = sprintf('env ELKARBACKUP_LEVEL="%s" ELKARBACKUP_EVENT="%s" ELKARBACKUP_URL="%s" ELKARBACKUP_ID="%s" ELKARBACKUP_PATH="%s" ELKARBACKUP_STATUS="%s" ELKARBACKUP_CLIENT_NAME="%s" ELKARBACKUP_JOB_NAME="%s" ELKARBACKUP_OWNER_EMAIL="%s" ELKARBACKUP_RECIPIENT_LIST="%s" ELKARBACKUP_CLIENT_TOTAL_SIZE="%s" ELKARBACKUP_JOB_TOTAL_SIZE="%s" ELKARBACKUP_JOB_RUN_SIZE="%s" ELKARBACKUP_JOB_STARTTIME="%s" ELKARBACKUP_JOB_ENDTIME="%s" ELKARBACKUP_SSH_ARGS="%s" "%s" 2>&1',
                    $model['level'],
                    $model['type'],
                    $model['clientUrl'],
                    $model['jobId'],
                    $model['jobRoot'],
                    $model['status'],
                    $model['clientName'],
                    $model['jobName'],
                    $model['ownerEmail'],
                    $model['recipientList'],
                    $model['clientDiskUsage'],
                    $model['jobTotalSize'],
                    $model['jobRunSize'],
                    $model['jobStartTime'],
                    $model['jobEndTime'],
                    $model['clientSshArgs'],
                    $scriptFile);
                exec($command, $commandOutput, $status);
                
                foreach($commandOutput as $key => $line) {
                    $find_r = strrpos($line, "\r");
                    if (false !== $find_r){
                        $commandOutput[$key] = substr($line, $find_r+1);
                    }
                }
                
                $commandOutputString = substr("\n" . implode("\n", $commandOutput), 0, 500); // Let's limit the output
                if (self::ERR_CODE_OK != $status) {
                    $this->err(
                        $errScriptError,
                        array(
                            '%entityid%'   => $model['jobId'],
                            '%scripttype%' => $model['type'],
                            '%scriptname%' => $script->getName(),
                            '%output%'     => $commandOutputString
                        ),
                        $model['context']
                        );
                    return self::ERR_CODE_PROC_EXEC_FAILURE;
                } else {
                    $this->info(
                        $errScriptOk,
                        array(
                            '%entityid%'   => $model['jobId'],
                            '%scripttype%' => $model['type'],
                            '%scriptname%' => $script->getName(),
                            '%output%'     => $commandOutputString
                        ),
                        $model['context']
                    );
                }
            }
        }
        return self::ERR_CODE_OK;
    }
    
    protected function getNameForLogs()
    {
        return $this->child_method();
    }
}
