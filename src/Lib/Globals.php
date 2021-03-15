<?php
/**
 * @copyright 2012,2013 Binovo it Human Project, S.L.
 * @license http://www.opensource.org/licenses/bsd-license.php New-BSD
 */

namespace App\Lib;

class Globals
{
    const STATUS_REPORT = 'StatusReport';

    public static function getSnapshotRoot($idClient, $job)
    {
        return sprintf('%s/%04d/%04d', $job->getBackupLocation()->getEffectiveDir(), $idClient, $job->getId());
    }

    public static function delTree($dir)
    {
        $allOk = true;
        if (!file_exists($dir)) {
            return true;
        }
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir") {
                        $allOk = $allOk && Globals::delTree($dir."/".$object);
                    } else {
                        $allOk = $allOk && unlink($dir."/".$object);
                    }
                }
            }
            reset($objects);
            $allOk = $allOk && rmdir($dir);
        } else {
            $allOk = $allOk && unlink($dir);
        }
        return $allOk;
    }
}