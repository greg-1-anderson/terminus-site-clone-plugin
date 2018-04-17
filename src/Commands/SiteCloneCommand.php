<?php
/**
 * Terminus Plugin that that adds command(s) to facilitate cloning sites on [Pantheon](https://www.pantheon.io)
 *
 * See README.md for usage information.
 */

namespace Pantheon\TerminusSiteClone\Commands;

use Pantheon\Terminus\Commands\TerminusCommand;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Commands\Site\SiteCommand;
use Pantheon\Terminus\Request\RequestAwareInterface;
use Pantheon\Terminus\Request\RequestAwareTrait;

/**
 * Site Clone Command
 * @package Pantheon\TerminusSiteClone\Commands
 */
class SiteCloneCommand extends SiteCommand
{
    
    /**
     * Copy the code, db and files from the specified Pantheon source site
     * to the specified Pantheon destination site.
     *
     * @command site:clone
     * @aliases site:copy
     * @param string $user_source The site UUID or machine name of the SOURCE (<site>.<env>)
     * @param string $user_destination The site UUID or machine name of the DESTINATION (<site>.<env>)
     * @param array $options
     * @option no-db Skip cloning the database
     * @option no-files Skip cloning the (media) files
     * @option no-code Skip cloning the code
     * @option no-backup Skip making a fresh backup for export from the source site AND skip backup creation before import on the destination site
     */
    public function clonePantheonSite(
            $user_source,
            $user_destination,
            $options = [
                'no-db' => false,
                'no-files' => false,
                'no-code' => false,
                'no-backup' => false,
        ])
    {
        // $user = $this->session()->getUser();

        $source = $this->fetchSiteDetails($user_source);
        $destination = $this->fetchSiteDetails($user_destination);

        if( $source['php_version'] !== $destination['php_version'] ){
            $this->log()->notice(
                'Warning: the source site has a PHP version of {src_php} and the destination site has a PHP version of {dest_php}',
                [
                    'src_php' => $source['php_version'],
                    'dest_php' => $destination['php_version'],
                ]
            );

            $proceed = $this->confirm('The sites do not have matching PHP versions. Would you like to proceed?');
            
            if (!$proceed) {
                return;
            }
        }

        if( $source['framework'] !== $destination['framework'] ){
            throw new TerminusException('Cannot clone sites with different frameworks.');
        }
        
        if( $source['frozen'] || $destination['frozen'] ){
            // @todo Ask the user if they want to unfreeze the site
            throw new TerminusException('Cannot clone sites that are frozen.');
        }

        $this->log()->notice(
            "Cloning from the {src_env} environment of {src} to the {dest_env} environment of {dest}...\n", 
            [ 
                'src' => $source['label'],
                'src_env' => $source['env'],
                'dest' => $destination['label'],
                'dest_env' => $destination['env'],
            ]
        );

        if( ! $options['no-backup'] ){
            // @todo Only create backups of the selected elements
            $backup_elements = $this->getBackupElements($options);

            // @todo Check if there is a recent backup and ask the user if they want to backup again
            $this->createBackup($source);
            
            $this->createBackup($destination);
            
            $source_backups = [];
            
            foreach( $backup_elements as $element ){
                $source_backups[$element] = $this->getLatestBackup($user_source, $source['env_raw'], $element);

                // todo: Prompt the user to download the backup file locally
            }

        }

    }

    /**
     * Fetch details of a site from '<site>.<env>' format
     *
     * @param string $site_env
     * @return array
     */
    private function fetchSiteDetails($site_env){
        $parsed = $this->getSiteEnv($site_env);
        $return = $parsed[0]->serialize();
        $return['site_raw'] = $parsed[0];
        // Turn the string value of 'true' or 'false' into a boolean
        $return['frozen'] = filter_var($return['frozen'], FILTER_VALIDATE_BOOLEAN);
        $return['env'] = $parsed[1]->id;
        $return['env_raw'] = $parsed[1];

        return $return;
    }

    /**
     * Get backup elements based on input options
     *
     * @param array $options
     * @return array
     */
    private function getBackupElements($options){
        $elements = [];
            
        if ( ! $options['no-db'] ) {
            $elements[] = 'db';
        }
        
        if ( ! $options['no-code'] ) {
            $elements[] = 'code';
        }
        
        if ( ! $options['no-files'] ) {
            $elements[] = 'files';
        }

        if( empty($elements) ){
            throw new TerminusNotFoundException('You cannot skip cloning all elements.');
        }

        return $elements;
    }

    private function createBackup($site, $elements = 'all' ){
        $this->log()->notice(
            'Creating a backup of the {env} environment for the {label} site...',
            [
                'label' => $site['label'],
                'env' => $site['env'],
            ]
        );

        $backup = $site['env_raw']->getBackups()->create();

        while (!$backup->checkProgress()) {
            // @todo: Add Symfony progress bar to indicate that something is happening.
        }
        
        $this->log()->notice(
            "Finished creating a backup of the {env} environment for the {label} site...\n",
            [
                'label' => $site['label'],
                'env' => $site['env'],
            ]
        );

        return $backup;
    }

    private function getLatestBackup($site_env, $env, $element='all')
    {
        $backup_options = ['file' => null, 'element' => $element, 'to' => null,];

        $backups = $env->getBackups($site_env, $backup_options)->getFinishedBackups();

        if (empty($backups)) {
            throw new TerminusNotFoundException(
                'No {element} backups available. Create one with `terminus backup:create {site}.{env}`',
                [
                    'element' => $element,
                    'site' => $source['name'],
                    'env' => $source['env'],
                ]
            );
        }

        $latest_backup = array_shift($backups);

        $return = $latest_backup->serialize();

        $return['url'] = $latest_backup->getUrl();

        return $return;
    }

}

/**
class SiteCloneBackups extends SingleBackupCommand implements RequestAwareInterface
{
    use RequestAwareTrait;

}
*/