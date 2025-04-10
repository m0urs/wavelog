<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class adif extends CI_Controller {

	/* Controls ADIF Import/Export Functions */

	function __construct()
	{
		parent::__construct();
		$this->load->helper(array('form', 'url'));

		$this->load->model('user_model');
		if(!$this->user_model->authorize(2) || !clubaccess_check(9)) { $this->session->set_flashdata('error', __("You're not allowed to do that!")); redirect('dashboard'); }
	}

	public function test() {
		if(validateADIFDate('20120228') == true){
			echo __("valid date");
		} else {
			echo __("date incorrect");
		}


	}

	// Export all QSO Data in ASC Order of Date.
	public function exportall()
	{
		// Set memory limit to unlimited to allow heavy usage
		ini_set('memory_limit', '-1');

		$this->load->model('adif_data');

		$data['qsos'] = $this->adif_data->export_all(null, $this->input->post('from', true), $this->input->post('to', true));

		$this->load->view('adif/data/exportall', $data);
	}


	// Export all QSO Data in ASC Order of Date.
	public function exportsat()
	{
		// Set memory limit to unlimited to allow heavy usage
		ini_set('memory_limit', '-1');

		$this->load->model('adif_data');

		$data['qsos'] = $this->adif_data->sat_all();

		$this->load->view('adif/data/exportsat', $data);
	}

	// Export all QSO Data in ASC Order of Date.
	public function exportsatlotw()
	{
		// Set memory limit to unlimited to allow heavy usage
		ini_set('memory_limit', '-1');

		$this->load->model('adif_data');

		$data['qsos'] = $this->adif_data->satellte_lotw();

		$this->load->view('adif/data/exportsat', $data);
	}

	public function export_custom() {
		// Set memory limit to unlimited to allow heavy usage
		ini_set('memory_limit', '-1');

		$this->load->model('adif_data');

		$station_id = $this->security->xss_clean($this->input->post('station_profile'));

		// Used for exporting QSOs not previously exported to LoTW
		if ($this->input->post('exportLotw') == 1) {
			$exportLotw = true;
		} else {
			$exportLotw = false;
		}

		$data['qsos'] = $this->adif_data->export_custom($this->input->post('from'), $this->input->post('to'), $station_id, $exportLotw);

		$this->load->view('adif/data/exportall', $data);


		if ($this->input->post('markLotw') == 1) {
			foreach ($data['qsos']->result() as $qso)
			{
				$this->adif_data->mark_lotw_sent($qso->COL_PRIMARY_KEY);
			}
		}
	}

	public function mark_lotw() {
		// Set memory limit to unlimited to allow heavy usage
		ini_set('memory_limit', '-1');

		$station_id = $this->security->xss_clean($this->input->post('station_profile'));
		$this->load->model('adif_data');

		$data['qsos'] = $this->adif_data->export_custom($this->input->post('from'), $this->input->post('to'), $station_id);

		foreach ($data['qsos']->result() as $qso)
		{
			$this->adif_data->mark_lotw_sent($qso->COL_PRIMARY_KEY);
		}

		$this->load->view('adif/mark_lotw', $data);
	}

	public function export_lotw()
	{
		// Set memory limit to unlimited to allow heavy usage
		ini_set('memory_limit', '-1');

		$this->load->model('adif_data');

		$data['qsos'] = $this->adif_data->export_lotw();

		$this->load->view('adif/data/exportall', $data);

		foreach ($data['qsos']->result() as $qso)
		{
			$this->adif_data->mark_lotw_sent($qso->COL_PRIMARY_KEY);
		}
	}

	public function index() {
		$this->load->model('contesting_model');
		$data['contests']=$this->contesting_model->getActivecontests();

		$this->load->model('stations');

		$data['page_title'] = __("ADIF Import / Export");
		$data['max_upload'] = ini_get('upload_max_filesize');

		if ($this->config->item('special_callsign') && clubaccess_check(9) && $this->session->userdata('clubstation') == 1) {
			$this->load->model('club_model');
			$data['club_operators'] = $this->club_model->get_club_members($this->session->userdata('user_id'));
		} else {
			$data['club_operators'] = false;
		}

		$data['station_profile'] = $this->stations->all_of_user();
		$active_station_id = $this->stations->find_active();
		$station_profile = $this->stations->profile($active_station_id);

		$data['active_station_info'] = $station_profile->row();
		$data['active_station_id'] = $active_station_id;

		$this->load->view('interface_assets/header', $data);
		$this->load->view('adif/import');
		$this->load->view('interface_assets/footer');
	}

	public function import() {
		$this->load->model('stations');
		$data['station_profile'] = $this->stations->all_of_user();

		$active_station_id = $this->stations->find_active();
		$station_profile = $this->stations->profile($active_station_id);

		$data['active_station_info'] = $station_profile->row();

		$data['page_title'] = __("ADIF Import");
		$data['tab'] = "adif";

		$config['upload_path'] = './uploads/';
		$config['allowed_types'] = 'adi|ADI|adif|ADIF|zip';

		log_message("Error","ADIF Start");
		session_write_close();
		$this->load->library('upload', $config);

		if ( ! $this->upload->do_upload()) {
			$data['error'] = $this->upload->display_errors();
			$data['max_upload'] = ini_get('upload_max_filesize');

			$this->load->view('interface_assets/header', $data);
			$this->load->view('adif/import', $data);
			$this->load->view('interface_assets/footer');
		} else {
			if ($this->stations->check_station_is_accessible($this->input->post('station_profile', TRUE))) {
				$contest=$this->input->post('contest', true) ?? '';
				$club_operator=$this->input->post('club_operator', true) ?? '';
				$stopnow=false;
				$fdata = array('upload_data' => $this->upload->data());
				ini_set('memory_limit', '-1');
				set_time_limit(0);

				$this->load->model('logbook_model');

				$f_elements=explode(".",$fdata['upload_data']['file_name']);
				if (strtolower($f_elements[count($f_elements)-1])=='zip') {
					$f_adif = preg_replace('/\\.zip$/', '', $fdata['upload_data']['file_name']);
					$p_adif = hash('sha256', $this->session->userdata('user_callsign') ).'.adif';
					if (preg_match("/.*\.adi.?$/",strtolower($p_adif))) {	// Check if adi? inside zip
						$zip = new ZipArchive;
						if ($zip->open('./uploads/'.$fdata['upload_data']['file_name'])) {
							$zip->extractTo("./uploads/",array($p_adif));
							$zip->close();
						}
						unlink('./uploads/'.$fdata['upload_data']['file_name']);
					} else {
						unlink('./uploads/'.$fdata['upload_data']['file_name']);
						$data['error'] = __("Unsupported Filetype");
						$stopnow=true;
					}
				} else {
					$p_adif=$fdata['upload_data']['file_name'];
				}
				if (!($stopnow)) {

					if (!$this->load->is_loaded('adif_parser')) {
						$this->load->library('adif_parser');
					}

					$this->adif_parser->load_from_file('./uploads/'.$p_adif);
					unlink('./uploads/'.$p_adif);
					$fdata['upload_data']='';	// free memory

					$this->adif_parser->initialize();
					$custom_errors = "";
					$alladif=[];
					$contest_qso_infos = [];
					while($record = $this->adif_parser->get_record()) {
						
						//overwrite the contest id if user chose a contest in UI
						if ($contest != '') {
							$record['contest_id'] = $contest;
						}

						//handle club operator
						if ($club_operator != '') {
							$record['operator'] = strtoupper($club_operator);
						}

						//check if contest_id exists in record and extract all found contest_ids
						if(array_key_exists('contest_id', $record)){
							$contest_id = $record['contest_id'];
							if($contest_id != ''){
								if(array_key_exists($contest_id, $contest_qso_infos)){
									$contest_qso_infos[$contest_id] += 1;
								}else{
									$contest_qso_infos[$contest_id] = 1;
								}
							}
						}

						if(count($record) == 0) {
							break;
						};
						array_push($alladif,$record);
					};
					$record='';	// free memory
					try {
						$custom_errors = $this->logbook_model->import_bulk($alladif, $this->input->post('station_profile', TRUE), $this->input->post('skipDuplicate'), $this->input->post('markClublog'),$this->input->post('markLotw'), $this->input->post('dxccAdif'), $this->input->post('markQrz'), $this->input->post('markEqsl'), $this->input->post('markHrd'), $this->input->post('markDcl'), true, $this->input->post('operatorName') ?? false, false, $this->input->post('skipStationCheck'));
					} catch (Exception $e) {
						log_message('error', 'Import error: '.$e->getMessage());
						$data['page_title'] = __("ADIF Import failed!");
						$this->load->view('interface_assets/header', $data);
						$this->load->view('adif/import_failed');
						$this->load->view('interface_assets/footer');
						return;
					}
				} else {	// Failure, if no ADIF inside ZIP
					$data['max_upload'] = ini_get('upload_max_filesize');
					$this->load->view('interface_assets/header', $data);
					$this->load->view('adif/import', $data);
					$this->load->view('interface_assets/footer');
					return;
				}
			} else {
				$custom_errors=__("Station Profile not valid for User");
			}

			log_message("Error","ADIF End");
			$data['adif_errors'] = $custom_errors;
			$data['skip_dupes'] = $this->input->post('skipDuplicate');
			$data['imported_contests'] = $contest_qso_infos;

			$data['page_title'] = __("ADIF Imported");
			$this->load->view('interface_assets/header', $data);
			$this->load->view('adif/import_success');
			$this->load->view('interface_assets/footer');
		}
	}

	public function dcl() {
		$this->load->model('stations');
		$data['station_profile'] = $this->stations->all_of_user();

		$data['page_title'] = __("DCL Import");
		$data['tab'] = "dcl";

		$config['upload_path'] = './uploads/';
		$config['allowed_types'] = 'adi|ADI|adif|ADIF';

		$this->load->library('upload', $config);

		if ( ! $this->upload->do_upload()) {
			$data['error'] = $this->upload->display_errors();

			$data['max_upload'] = ini_get('upload_max_filesize');

			$this->load->view('interface_assets/header', $data);
			$this->load->view('adif/import', $data);
			$this->load->view('interface_assets/footer');
		} else {
			$data = array('upload_data' => $this->upload->data());

			ini_set('memory_limit', '-1');
			set_time_limit(0);

			$this->load->model('logbook_model');

			if (!$this->load->is_loaded('adif_parser')) {
				$this->load->library('adif_parser');
			}

			$this->adif_parser->load_from_file('./uploads/'.$data['upload_data']['file_name']);

			$this->adif_parser->initialize();
			$error_count = array(0, 0, 0);
			$custom_errors = "";
			while($record = $this->adif_parser->get_record())
			{
				if(count($record) == 0) {
					break;
				};

				$dok_result = $this->logbook_model->update_dok($record, $this->input->post('ignoreAmbiguous'), $this->input->post('onlyConfirmed'), $this->input->post('overwriteDok'));
				if (!empty($dok_result)) {
					switch ($dok_result[0]) {
					case 0:
						$error_count[0]++;
						break;
					case 1:
						$custom_errors .= $dok_result[1];
						$error_count[1]++;
						break;
					case 2:
						$custom_errors .= $dok_result[1];
						$error_count[2]++;
					}
				}
			};
			unlink('./uploads/'.$data['upload_data']['file_name']);
			$data['dcl_error_count'] = $error_count;
			$data['dcl_errors'] = $custom_errors;
			$data['page_title'] = __("DCL Data Imported");
			$this->load->view('interface_assets/header', $data);
			$this->load->view('adif/dcl_success');
			$this->load->view('interface_assets/footer');
		}
	}
}

/* End of file adif.php */
