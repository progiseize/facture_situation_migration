<?php
/* Copyright (C) 2023       Progiseize        <contact@progiseize.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';

class FactureSituationMigration {

    
    /**
     * @var string Table migration name.
     */
    public $table_migration = 'facture_situation_migration';

    /**
     * @var string Table backup.
     */
    public $table_backupdet = 'facture_situation_migration_backup';

    /**
     * @var string Table facture name.
     */
    public $table_facture = 'facture';

    /**
     * @var string Table facturedet name.
     */
    public $table_facturedet = 'facturedet';

    /**
     * @var string Table const
     */
    public $table_const = 'const';

    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var string Error
     */
    public $error = '';

    /**
     * @var array Errors
     */
    public $errors = array();

    /**
     * @var DoliDB Database handler.
     */
    public function __construct($_db){
        global $db;
        $this->db = is_object($_db) ? $_db : $db;
    }

    public function setFactureDone($facture_id){

        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_migration;
        $sql.= " SET done = 1 ";
        $sql.= " WHERE rowid = '".$this->db->escape($facture_id)."'";
        $res = $this->db->query($sql);

        if(!$res): return 0; endif;
        return 1;
    }


    /**
     *  We make an invoice data backup
     */
    public function migration_step_1(){

        global $conf;

        // IF ALREADY DONE
        if(isset($conf->global->MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP) && intval($conf->global->MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP) > 0): return -3; endif;

        // CREATE
        $sql_create = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX.$this->table_backupdet." LIKE ".MAIN_DB_PREFIX.$this->table_facturedet;
        $result_create = $this->db->query($sql_create);
        if(!$result_create): return -1; endif;

        // BACKUP
        $sql_copy = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_backupdet." SELECT * FROM ".MAIN_DB_PREFIX.$this->table_facturedet;
        $result_copy = $this->db->query($sql_copy);
        if(!$result_copy): return -2; endif;

        return 1;
    }

    /**
     *  Store ID facture_situation into facture_situation_migration
     *  in order to check if migration is done
     */
    public function migration_step_2(){

        global $conf;

        // On récupère tous les identifiants des factures de situations
        $sql = "SELECT rowid, entity, situation_cycle_ref FROM ".MAIN_DB_PREFIX.$this->table_facture." WHERE type = '".facture::TYPE_SITUATION."'";
        $res = $this->db->query($sql);

        if($res){

            // SI FACTURES DE SITUATIONS
            if($res->num_rows > 0){

                $insert_success = 0;
                $insert_exist = 0;

                while ($obj = $this->db->fetch_object($res)){
                    
                    $sql_insert = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_migration;
                    $sql_insert.= " (rowid,situation_cycle_ref,entity,done) VALUES (";
                    $sql_insert.= "'".$this->db->escape($obj->rowid)."',";
                    $sql_insert.= "'".$this->db->escape($obj->situation_cycle_ref)."',";
                    $sql_insert.= "'".$this->db->escape($obj->entity)."',";
                    $sql_insert.= "'0'";
                    $sql_insert.= ")";

                    $res_insert = $this->db->query($sql_insert);
                    if($res_insert){$insert_success++;}
                    elseif(!$res_insert && $this->db->db->lasterrno == 'DB_ERROR_RECORD_ALREADY_EXISTS'){
                        $insert_exist++;
                    }
                    
                }

                // SUCCES
                if($insert_success == $res->num_rows){return 1;} 
                // SUCCES EN CAS D'ERREURS PRECEDENTES
                elseif($insert_success + $insert_exist == $res->num_rows && $insert_exist != $res->num_rows){ return 2;}
                // AUCUNE INSERTION NECESSAIRE
                elseif($insert_exist == $res->num_rows){ return 3;} 
                // ERREUR INSERTION
                else {
                    $this->error = 'Erreur INSERT';
                    return -2;
                }
            } 
            // AUCUNE FACTURE DE SITUATION
            else { return 0; }

        // ERREUR SQL SELECT
        } else {
            $this->error = 'Erreur SQL';
            return -1;
        }
    }

    /**
     *  Convert DATA to the new method by cycle_ref
     *  situation_percent, total_ht, total_tva, total_ttc, multicurrency_total_ht, multicurrency_total_tva, multicurrency_total_ttc
     *  localtax ?
     */
    public function migration_step_3(){

        // ON RECUPERE ET REGROUPE LES REFERENCES DE CYCLE
        $sql = "SELECT";
        $sql.= " DISTINCT situation_cycle_ref";
        $sql.= " FROM ".MAIN_DB_PREFIX.$this->table_migration; 
        $sql.= " WHERE done = '0'";

        $res = $this->db->query($sql);
        if($res):

            // RETURN 1 IF ALL ALREADY DONE
            if($res->num_rows == 0): return 1; endif;

            $cycle_array = array();

            $nb_update = 0;
            $nb_update_success = 0;
            $nb_update_error = 0;

            // POUR CHAQUE CYCLE
            while ($obj = $this->db->fetch_object($res)): $nb_update++;

                $sql_bis = "SELECT";
                $sql_bis.= " f.rowid as facture_id, f.ref as facture_ref, f.situation_cycle_ref as facture_cycle_ref, f.situation_counter as facture_situation_counter, f.situation_final as facture_situation_final";
                $sql_bis.= " , fd.rowid as ligne_id, fd.situation_percent as ligne_percent, fd.fk_prev_id as ligne_prev_id";
                $sql_bis.= " , fd.subprice as ligne_subprice, fd.total_ht as ligne_total_ht, fd.total_tva as ligne_total_tva, fd.total_ttc as ligne_total_ttc";
                $sql_bis.= " , fd.multicurrency_subprice as ligne_multicurrency_subprice, fd.multicurrency_total_ht as ligne_multicurrency_total_ht, fd.multicurrency_total_tva as ligne_multicurrency_total_tva, fd.multicurrency_total_ttc as ligne_multicurrency_total_ttc";
                $sql_bis.= " FROM ".MAIN_DB_PREFIX.$this->table_facture." AS f";
                $sql_bis.= " INNER JOIN ".MAIN_DB_PREFIX.$this->table_facturedet." AS fd ON f.rowid = fd.fk_facture";
                $sql_bis.= " WHERE situation_cycle_ref = '".$obj->situation_cycle_ref."'";
                $sql_bis.= " ORDER BY f.situation_cycle_ref DESC, f.situation_counter DESC";
                //echo $sql_bis.'<br>';
                
                $res_bis = $this->db->query($sql_bis);
                if($res_bis):

                    // POUR CHAQUE CYCLE
                    while ($obj_bis = $this->db->fetch_object($res_bis)){
                        
                        if(!isset($cycle_array[$obj_bis->facture_cycle_ref])){
                            $cycle_array[$obj_bis->facture_cycle_ref] = array();
                        }
                        if(!isset($cycle_array[$obj_bis->facture_cycle_ref][$obj_bis->facture_situation_counter])){
                            $cycle_array[$obj_bis->facture_cycle_ref][$obj_bis->facture_situation_counter] = array(
                                'facture_id' => $obj_bis->facture_id,
                                'facture_ref' => $obj_bis->facture_ref,
                                'lines' => array(),
                            );
                        }

                        $cycle_array[$obj_bis->facture_cycle_ref][$obj_bis->facture_situation_counter]['lines'][$obj_bis->ligne_id] = array(
                            'line_percent' => $obj_bis->ligne_percent,
                            'fk_prev_id' => $obj_bis->ligne_prev_id,
                            'subprice' => $obj_bis->ligne_subprice,
                            'ligne_total_ht' => $obj_bis->ligne_total_ht,
                            'ligne_total_tva' => $obj_bis->ligne_total_tva,
                            'ligne_total_ttc' => $obj_bis->ligne_total_ttc,
                            'multicurrency_subprice' => $obj_bis->ligne_multicurrency_subprice,
                            'multicurrency_ligne_total_ht' => $obj_bis->ligne_multicurrency_total_ht,
                            'multicurrency_ligne_total_tva' => $obj_bis->ligne_multicurrency_total_tva,
                            'multicurrency_ligne_total_ttc' => $obj_bis->ligne_multicurrency_total_ttc,
                        );
                    }

                    $cycle_update = 0;
                    $cycle_update_success = 0;
                    $cycle_update_error = 0;

                    $this->db->begin();

                    // TABLEAU CONSTRUIT, ON RECONSTRUIT LES VALEURS - CYCLE_ARRAY[CYCLE_REF][SITUATION_COUNTER][INFOS]
                    foreach ($cycle_array as $cycle_ref => $cycle_infos): $cycle_update++;
                        
                        $facture_update = 0;
                        $facture_update_success = 0;
                        $facture_update_error = 0;

                        // On remets dans l'ordre les cycles au cas ou
                        krsort($cycle_infos);

                        // Pour chaque facture du cycle
                        foreach ($cycle_infos as $situcounter => $facture_infos):

                            $facture_update++;

                            $factureline_update = 0;
                            $factureline_update_success = 0;
                            $factureline_update_error = 0;

                            // Si on est sur une situation > 1 dans le cycle, on recalcule, la situation 1 est toujours correcte
                            if(intval($situcounter) > 1):

                                $situcounter_before = intval($situcounter) - 1;

                                // Pour chaque ligne de la facture
                                foreach($facture_infos['lines'] as $line_id => $line_infos): 

                                    $factureline_update++;
                                    
                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['line_percent'] = floatval($line_infos['line_percent']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['line_percent']);
                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['ligne_total_ht'] = floatval($line_infos['ligne_total_ht']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['ligne_total_ht']);
                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['ligne_total_tva'] = floatval($line_infos['ligne_total_tva']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['ligne_total_tva']);
                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['ligne_total_ttc'] = floatval($line_infos['ligne_total_ttc']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['ligne_total_ttc']);

                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['multicurrency_ligne_total_ht'] = floatval($line_infos['multicurrency_ligne_total_ht']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['multicurrency_ligne_total_ht']);
                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['multicurrency_ligne_total_tva'] = floatval($line_infos['multicurrency_ligne_total_tva']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['multicurrency_ligne_total_tva']);
                                    $cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['multicurrency_ligne_total_ttc'] = floatval($line_infos['multicurrency_ligne_total_ttc']) - floatval($cycle_array[$cycle_ref][$situcounter_before]['lines'][$line_infos['fk_prev_id']]['multicurrency_ligne_total_ttc']);

                                    $sql_update = "UPDATE ".MAIN_DB_PREFIX.$this->table_facturedet." SET";
                                    $sql_update.= " situation_percent = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['line_percent']."',";
                                    $sql_update.= " total_ht = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['ligne_total_ht']."',";
                                    $sql_update.= " total_tva = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['ligne_total_tva']."',";
                                    $sql_update.= " total_ttc = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['ligne_total_ttc']."',";
                                    $sql_update.= " multicurrency_total_ht = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['multicurrency_ligne_total_ht']."',";
                                    $sql_update.= " multicurrency_total_tva = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['multicurrency_ligne_total_tva']."',";
                                    $sql_update.= " multicurrency_total_ttc = '".$cycle_array[$cycle_ref][$situcounter]['lines'][$line_id]['multicurrency_ligne_total_ttc']."'";                                    
                                    $sql_update.= " WHERE rowid = '".$line_id."';";

                                    $res_update = $this->db->query($sql_update);

                                    if($res_update): $factureline_update_success++;
                                    else: $factureline_update_error++; endif;

                                endforeach;
                            endif;

                            if(!$factureline_update_error):
                                // SI PAS ERREUR -> FACTURE DONE
                                $this->setFactureDone($facture_infos['facture_id']);
                                $facture_update_success++;
                            else:
                                $facture_update_error++;
                            endif;

                        endforeach;
                        
                        if(!$facture_update_error): $cycle_update_success++;
                        else: $this->db->rollback(); $cycle_update_error++; endif;

                    endforeach;

                    if(!$cycle_update_error): $nb_update_success++; $this->db->commit();
                    else: $nb_update_error++;  $this->db->rollback(); endif; 
                    
                endif;
            endwhile;

            // SI TOUT EST FAIT
            if($nb_update == $nb_update_success): return 2;
            // SI RESULTATS POSITIFS ET NEGATIFS
            elseif($nb_update_success > 0 && $nb_update_error > 0): return -1;
            // TOUT EN ERREUR
            elseif($nb_update == $nb_update_error): return -2;
            endif;
        endif;
    }

    /**
     *  Set INVOICE_USE_SITUATION to 2 for all entities WHERE INVOICE_USE_SITUATION == 1
     */
    public function setConstSituNewMethod($all_entities = 1){

        $sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_const;
        $sql.= " SET value='2'";
        $sql.= " WHERE name = 'INVOICE_USE_SITUATION' AND value = '1'";

        $res = $this->db->query($sql);
        if(!$res): return 0; endif;
        return 1;
    }
    
}

?>