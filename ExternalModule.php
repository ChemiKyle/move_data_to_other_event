<?php

namespace MDOE\ExternalModule;

use ExternalModules\AbstractExternalModule;
use REDCap;

class ExternalModule extends AbstractExternalModule {

    function redcap_every_page_top($project_id) {

        $project_settings = $this->framework->getProjectSettings();

        if (!$project_settings['active']['value']) {
            return;
        }

        if ( !$this->framework->getUser()->hasDesignRights() &&
                ( $this->getSystemSetting('restrict_to_designers_global') ||
                  !$project_settings['allow_non_designers']['value']) )
        {
            return;
        }

        if (PAGE != 'DataEntry/record_home.php' || !$_REQUEST['id']) return;
        // __DIR__ . '/migratedata.php'; does not work due to VM symlink(?)
        $ajax_page = json_encode($this->framework->getUrl("migratedata.php"));

        echo ("<script> var ajaxpage = {$ajax_page}; </script>");
        include('div.html');
        $this->includeJs('js/mdoe.js');
    }

    function includeJs($path) {
        echo '<script src="' . $this->getUrl($path) . '">;</script>';
    }

    function moveEvent($source_event_id, $target_event_id, $record_id = NULL, $project_id = NULL, $form_names = NULL) {
        $record_id = $record_id ?: ( ($this->framework->getRecordId()) ?: NULL ); // return in place of NULL causes errors
        $project_id = $project_id ?: ( ($this->framework->getProjectId()) ?: NULL );
        $record_pk = REDCap::getRecordIdField();
        $form_names = implode("', '", $form_names);

        //TODO: sanitize without mysqli_real_escape_string
        $sql = "SELECT a.field_name FROM redcap_metadata as a
            INNER JOIN (SELECT form_name FROM redcap_events_forms WHERE event_id = " . ($source_event_id) .  ")
            as b ON a.form_name = b.form_name
            WHERE a.project_id = " . ($project_id) . "
            AND a.form_name IN ('" . $form_names . "')
            ORDER BY field_order ASC;";

        $fields = [];
        $result= $this->framework->query($sql);

        while ($row = $result->fetch_assoc()) {
            $fields[] = $row["field_name"];
        }

        $get_data = [
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => $record_id,
            'fields' => $fields,
            'events' => $source_event_id
        ];

        $new_data = [];

        // get record for selected event, swap source_event_id for target_event_id
        $old_data = REDCap::getData($get_data);
        $new_data[$record_id][$target_event_id] = $old_data[$record_id][$source_event_id];

        $response = REDCap::saveData($project_id, 'array', $new_data, 'normal');
        $log_message = "Migrated form(s) " . $form_names . " from event " . $source_event_id . " to " . $target_event_id;

        // soft delete all data for each field
        // document fields do not migrate or soft delete via saveData
        array_walk_recursive($old_data[$record_id][$source_event_id], function(&$value, $key) {
                if ($key !== $record_pk) {
                    $value = NULL;
                }
            }
        );

        $delete_response = REDCap::saveData($project_id, 'array', $old_data, 'overwrite');

        $log_message = $this->forceMigrateSourceFields($get_data, $project_id, $record_id, $source_event_id, $target_event_id, $log_message);

        REDCap::logEvent("Moved data from an event to a different event", $log_message);

        // TODO: parse response, use as flag for deletion
        return json_encode($delete_response);

        // if moving an entire event, event data is deleted via call to core JS function, deleteEventInstance which wraps \Controller\DataEntryController
        // requires POST and GET data from the record_home page
    }

    function forceMigrateSourceFields($get_data, $project_id, $record_id, $source_event_id, $target_event_id, $log_message) {
        $check_old = REDCap::getData($get_data)[$record_id][$source_event_id];

        // check for fields which did not transfer
        $revisit_fields = [];
        foreach ($check_old as $field => $value) {
            if ($value !== '' && $value !== '0' && $value !== NULL &&
                $field != REDCap::getRecordIdField()) {
		        array_push($revisit_fields, "'$field'");
            }
        }

         if ($revisit_fields !== [])  {
             // Raw SQL to transfer docs which do not transfer or delete with saveData
             // explicitly excluding the record's primary key
             $revisit_fields = implode(',', $revisit_fields);
             $log_message .= ". Forced transfer of additional field(s): " . $revisit_fields;
             $docs_xfer_sql = "UPDATE redcap_data SET event_id = " . $target_event_id . "
                 WHERE project_id = " . $project_id . "
                 AND event_id = " . $source_event_id . "
                 AND record = " . $record_id . "
                 AND field_name NOT IN ('" . REDCap::getRecordIdField() . "')
                 AND field_name IN (" . $revisit_fields . ");";
             $this->framework->query($docs_xfer_sql);
        }
        return $log_message;
    }
}
