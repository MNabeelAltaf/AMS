<?php defined('BASEPATH') or exit('No direct script access allowed');

class Leaves extends CI_Controller
{
	public $data = [];

	public function __construct()
	{
		parent::__construct();
	}

	public function index()
	{
		if ($this->ion_auth->logged_in()  && is_module_allowed('leaves') && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {
			$this->data['page_title'] = 'Leaves - ' . company_name();
			$this->data['main_page'] = 'Leaves Application';
			$this->data['current_user'] = $this->ion_auth->user()->row();
			if ($this->ion_auth->is_admin() || permissions('leaves_view_all')) {
				$this->data['system_users'] = $this->ion_auth->members()->result();
			} elseif (permissions('leaves_view_selected')) {
				$selected = selected_users();
				foreach ($selected as $user_id) {
					$users[] = $this->ion_auth->user($user_id)->row();
				}
				$users[] = $this->ion_auth->user($this->session->userdata('user_id'))->row();
				$this->data['system_users'] = $users;
			}
			$saas_id = $this->session->userdata('saas_id');
			$this->db->where('saas_id', $saas_id);
			$query = $this->db->get('leaves_type');
			$this->data['leaves_types'] = $query->result_array();
			// echo json_encode($this->data["leaves_types"]);
			$this->load->view('leaves', $this->data);
		} else {
			redirect('auth', 'refresh');
		}
	}

	public function delete($id = '')
	{
		if ($this->ion_auth->logged_in() && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {

			if (empty($id)) {
				$id = $this->uri->segment(3) ? $this->uri->segment(3) : '';
			}

			if (!empty($id) && is_numeric($id) && $this->leaves_model->delete($id)) {
				$this->session->set_flashdata('message', $this->lang->line('deleted_successfully') ? $this->lang->line('deleted_successfully') : "Deleted successfully.");
				$this->session->set_flashdata('message_type', 'success');

				$this->data['error'] = false;
				$this->data['message'] = $this->lang->line('deleted_successfully') ? $this->lang->line('deleted_successfully') : "Deleted successfully.";
				echo json_encode($this->data);
			} else {

				$this->data['error'] = true;
				$this->data['message'] = $this->lang->line('something_wrong_try_again') ? $this->lang->line('something_wrong_try_again') : "Something wrong! Try again.";
				echo json_encode($this->data);
			}
		} else {
			$this->data['error'] = true;
			$this->data['message'] = $this->lang->line('access_denied') ? $this->lang->line('access_denied') : "Access Denied";
			echo json_encode($this->data);
		}
	}

	public function edit()
	{
		if ($this->ion_auth->logged_in() && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {
			$this->form_validation->set_rules('update_id', 'Leave ID', 'trim|required|strip_tags|xss_clean|is_numeric');
			$this->form_validation->set_rules('leave_reason', 'Leave Reason', 'trim|required|strip_tags|xss_clean');

			if ($this->form_validation->run() == TRUE) {

				$employeeIdQuery = $this->db->select('employee_id')->get_where('users', array('id' => $this->input->post('user_id') ? $this->input->post('user_id') : $this->session->userdata('user_id')));
				if ($employeeIdQuery->num_rows() > 0) {
					$employeeIdRow = $employeeIdQuery->row();
					$employeeId = $employeeIdRow->employee_id;
					$data['user_id'] = $employeeId;
				}
				$data['leave_reason'] = $this->input->post('leave_reason');
				$data['type'] = $this->input->post('type');
				$data['paid'] = $this->input->post('paid');
				if ($this->input->post('status') == 1) {
					$this->db->where('id',  $this->input->post('update_id'));
					$query = $this->db->get('leaves');
					$leave = $query->row();
					$step = $leave->step;

					$this->db->where('saas_id', $this->session->userdata('saas_id'));
					$this->db->order_by('step_no', 'desc'); 
					$this->db->limit(1);
					$heiQuery = $this->db->get('leave_hierarchy');
					$heiResult = $heiQuery->row();
					$highStep = $heiResult->step_no;
					if ($step == $highStep) {
						$data['status'] = '1';
						$data['step'] = $step + 1;
					} else {
						$data['status_last'] = $this->input->post('status') ? $this->input->post('status') : '0';
						$data['step'] = $step + 1;
					}
				} elseif ($this->input->post('status') == 2) {
					$data['step'] = $highStep;
					$data['status'] = '2';
				} elseif ($this->input->post('status') == 0) {
					$this->db->where('id',  $this->input->post('update_id'));
					$query = $this->db->get('leaves');
					$leave = $query->row();
					$step = $leave->step;
					$status = $leave->status;
					if ($status != $this->input->post('status')) {
						$data['status'] = '0';
						$data['step'] = $step - 1;
					}else{
						$data['status'] = '0';
					}
				}

				// Get shift details based on shift_id
				$shiftIdQuery = $this->db->select('shift_id')->get_where('users', array('id' => $this->input->post('user_id') ? $this->input->post('user_id') : $this->session->userdata('user_id')));
				$shiftIdRow = $shiftIdQuery->row();
				$shiftId = $shiftIdRow->shift_id;

				if ($shiftId !== '0') {
					$shiftQuery = $this->db->get_where('shift', array('id' => $shiftId));
					$shiftRow = $shiftQuery->row();
					$checkInDept = $shiftRow->starting_time;
					$breakEndDept = $shiftRow->break_end;
					$breakStartDept = $shiftRow->break_start;
					$checkOutDept = $shiftRow->ending_time;
				} else {
					// Set default times from shift with ID 1
					$defaultShiftQuery = $this->db->get_where('shift', array('id' => 1));
					$defaultShiftRow = $defaultShiftQuery->row();
					$checkInDept = $defaultShiftRow->starting_time;
					$breakEndDept = $defaultShiftRow->break_end;
					$breakStartDept = $defaultShiftRow->break_start;
					$checkOutDept = $defaultShiftRow->ending_time;
				}

				if (strpos($this->input->post('leave_duration'), 'Full') !== false) {
					$starting_date = $this->input->post('starting_date');
					$ending_date = $this->input->post('ending_date');
					$data['starting_date'] = date("Y-m-d", strtotime($starting_date));
					$data['ending_date'] = date("Y-m-d", strtotime($ending_date));
					$data['starting_time'] = format_date($checkInDept, "H:i:s");
					$data['ending_time'] = format_date($checkOutDept, "H:i:s");

					$diffInSeconds = strtotime($data['ending_date']) - strtotime($data['starting_date']);
					$leave_duration = 1 + round(abs($diffInSeconds) / 86400);
					$data['leave_duration'] = $leave_duration . ($leave_duration > 1 ? " Full Days" : " Full Day");


					if (strtotime($checkInDept) > strtotime($checkOutDept)) {
						$data['ending_date'] = date("Y-m-d", strtotime("+1 day", strtotime($starting_date)));
					}
					if (!empty($_FILES['documents']['name'])) {
						$upload_path = 'assets/uploads/f' . $this->session->userdata('saas_id') . '/leaves/';

						if (!is_dir($upload_path)) {
							mkdir($upload_path, 0775, true);
						}
						$config['upload_path'] = $upload_path;
						$config['allowed_types'] = '*';
						$config['overwrite'] = false;
						$config['max_size'] = 0;
						$config['max_width'] = 0;
						$config['max_height'] = 0;
						$this->load->library('upload', $config);
						if ($this->upload->do_upload('documents')) {
							$uploaded_data = $this->upload->data('file_name');
							$data['document'] = $uploaded_data;
						}
					}
					$missing_finger_days = 0;
					$finger_count = 0;
					$holiday_count = 0;
					$current_date = new DateTime($starting_date);
					$end_date = new DateTime($ending_date);
					$user_id = $this->input->post('user_id') ? $this->input->post('user_id') : $this->session->userdata('user_id');
					$employee_id_query = $this->db->query("SELECT employee_id FROM users WHERE id = $user_id");
					$employee_id_result = $employee_id_query->row_array();
					$employee_id = $employee_id_result['employee_id'];


					while ($current_date < $end_date) {
						$formatted_date = $current_date->format('Y-m-d');
						$execution = false;

						$holidayQuery = $this->db->query("SELECT * FROM holiday");
						$holidays = $holidayQuery->result_array();


						foreach ($holidays as $value4) {
							$startDate = $value4["starting_date"];
							$endDate = $value4["ending_date"];
							$apply = $value4["apply"];
							$startDateTimestamp  = strtotime($startDate);
							$endDateTimestamp  = strtotime($endDate);
							$dateToCheckTimestamp  = strtotime($formatted_date);
							if ($apply == '1' && $dateToCheckTimestamp >= $startDateTimestamp && $dateToCheckTimestamp <= $endDateTimestamp) {
								$departments = json_decode($value4["department"]);
								foreach ($departments as $department) {
									$user_ids_query = $this->db->query("SELECT * FROM users WHERE department = $department AND employee_id= $employee_id");
									$user_ids_result = $user_ids_query->result_array();
									if (count($user_ids_result) > 0) {
										if (!$execution) {
											$missing_finger_days++;
											$execution = true;
										}
									}
								}
							} elseif ($apply == '2' && $dateToCheckTimestamp >= $startDateTimestamp && $dateToCheckTimestamp <= $endDateTimestamp) {
								$holidayUsers = json_decode($value4["users"]);
								foreach ($holidayUsers as $holidayUser) {
									$user_ids_query = $this->db->query("SELECT * FROM users WHERE id = $holidayUser AND employee_id= $employee_id");
									$user_ids_result = $user_ids_query->result_array();
									if (count($user_ids_result) > 0) {
										if (!$execution) {
											$missing_finger_days++;
											$execution = true;
										}
									}
								}
							}
						}


						$current_date->modify('+1 day');
					}

					// Subtract the number of missing finger days and holidays from the leave_duration
					$data['leave_duration'] = ($data['leave_duration'] - $missing_finger_days) . " Full Day/s";
				} elseif (strpos($this->input->post('leave_duration'), 'Half') !== false) {
					$half_day_period = $this->input->post('half_day_period');
					$startingTime = $half_day_period === "0" ? $checkInDept : $breakEndDept;
					$endingTime = $half_day_period === "0" ? $breakStartDept : $checkOutDept;
					$half_day_period = $half_day_period === "0" ? "First Time" : "Second Time";
					$data['starting_date'] = date("Y-m-d", strtotime($this->input->post('date_half')));
					$data['ending_date'] = date("Y-m-d", strtotime($this->input->post('date_half')));
					$data['starting_time'] = date("H:i:s", strtotime($startingTime));
					$data['ending_time'] = date("H:i:s", strtotime($endingTime));
					$data['leave_duration'] = $half_day_period . " Half Day";
					if (strtotime($checkInDept) > strtotime($checkOutDept)) {
						$tempStartingDate = date("Y-m-d", strtotime($this->input->post('date_half')));
						$tempEndingDate = date("Y-m-d", strtotime("+1 day", strtotime($tempStartingDate)));

						// Check if starting time is after the temp ending date
						if ($half_day_period === "Second Time" && strtotime($startingTime) >= strtotime('00:00:00', strtotime($tempStartingDate))) {
							$startingDate = $tempEndingDate;
						}

						// Check if ending time is after the starting date
						if (strtotime($endingTime) >= strtotime('00:00:00', strtotime($tempStartingDate))) {
							$endingDate = $tempEndingDate;
						}
						$data['starting_date'] = $startingDate;
						$data['ending_date'] = $endingDate;
					}
				} elseif (strpos($this->input->post('leave_duration'), 'Short') !== false) {
					$data['starting_date'] = date("Y-m-d", strtotime($this->input->post('date')));
					$data['ending_date'] = date("Y-m-d", strtotime($this->input->post('date')));
					$data['starting_time'] = date("H:i:s", strtotime($this->input->post('starting_time')));
					$data['ending_time'] = date("H:i:s", strtotime($this->input->post('ending_time')));
					$startingTime = strtotime($this->input->post('starting_time'));
					$endingTime = strtotime($this->input->post('ending_time'));
					$durationSeconds = $endingTime - $startingTime;
					$durationHours = floor($durationSeconds / 3600);
					$durationMinutes = floor(($durationSeconds % 3600) / 60);
					$data['leave_duration'] = $durationHours . " hrs " . $durationMinutes . " mins " . " Short Leave";
					if (strtotime($checkInDept) > strtotime($checkOutDept)) {
						$startingDate = date("Y-m-d", strtotime($this->input->post('date')));
						$endingDate = date("Y-m-d", strtotime($this->input->post('date')));
						$tempEndingDate = date("Y-m-d", strtotime("+1 day", strtotime($startingDate)));

						// Check if starting time is after the temp ending date
						if ($startingTime >= strtotime('00:00:00', strtotime($tempEndingDate))) {
							$startingDate = $tempEndingDate;
						}

						if ($endingTime >= strtotime('00:00:00', strtotime($startingDate))) {
							$endingDate = $tempEndingDate;
						}

						if ($startingTime < $endingTime) {
							$startingDate = $tempEndingDate;
							$endingDate = $tempEndingDate;
						}

						$data['starting_date'] = $startingDate;
						$data['ending_date'] = $endingDate;

						if ($endingTime < $startingTime) {
							// Calculate duration for the first day
							$startOfDay = strtotime('00:00:00', strtotime($data['starting_date']));
							$durationFirstDay = strtotime('23:59:59', strtotime($data['starting_date'])) - $startingTime;

							// Calculate duration for the second day
							$endOfDay = strtotime('23:59:59', strtotime($data['ending_date']));
							$durationSecondDay = $endingTime - $startOfDay;

							$durationSeconds = $durationFirstDay + $durationSecondDay;
						} else {
							$durationSeconds = $endingTime - $startingTime;
						}

						$durationHours = floor($durationSeconds / 3600);
						$durationMinutes = floor(($durationSeconds % 3600) / 60);
						$data['leave_duration'] = $durationHours . " hrs " . $durationMinutes . " mins " . " Short Leave";
					}
				}

				if ($this->input->post('status')) {
					if ($this->input->post('status') == 1) {
						$to_user = $this->ion_auth->user($this->input->post('user_id'))->row();
						$template_data = array();
						$template_data['NAME'] = $to_user->first_name . ' ' . $to_user->last_name;
						$type = $this->input->post('type');
						$template_data['LEAVE_TYPE'] = '';
						$querys = $this->db->query("SELECT * FROM leaves_type");
						$leaves = $querys->result_array();
						if (!empty($leaves)) {
							foreach ($leaves as $leave) {
								if ($type == $leave['id']) {
									$template_data['LEAVE_TYPE'] = $leave['name'];
								}
							}
						}

						$template_data['STARTING_DATE'] = $data['starting_date'] . ' ' . $data['starting_time'];
						$template_data['REASON'] = $this->input->post('leave_reason');
						$template_data['DUE_DATE'] = $data['ending_date'] . ' ' . $data['ending_time'];
						$template_data['LEAVE_REQUEST_URL'] = base_url('leaves');
						$email_template = render_email_template('leave_accept', $template_data);
						send_mail($to_user->email, $email_template[0]['subject'], $email_template[0]['message']);
						$notification_data = array(
							'notification' => 'leave request accepted',
							'type' => 'leave_request_accepted',
							'type_id' => $this->input->post('update_id'),
							'from_id' => $this->session->userdata('user_id'),
							'to_id' => $this->input->post('user_id') ? $this->input->post('user_id') : $this->session->userdata('user_id'),
						);
						$notification_id = $this->notifications_model->create($notification_data);
					} elseif ($this->input->post('status') == 2) {
						$notification_data = array(
							'notification' => 'leave request rejected',
							'type' => 'leave_request_rejected',
							'type_id' => $this->input->post('update_id'),
							'from_id' => $this->session->userdata('user_id'),
							'to_id' => $this->input->post('user_id') ? $this->input->post('user_id') : $this->session->userdata('user_id'),
						);
						$notification_id = $this->notifications_model->create($notification_data);
					}
				}

				if ($this->leaves_model->edit($this->input->post('update_id'), $data)) {
					$this->session->set_flashdata('message', $this->lang->line('updated_successfully') ? $this->lang->line('updated_successfully') : "Updated successfully.");
					$this->session->set_flashdata('message_type', 'success');
					$this->data['error'] = false;
					$this->data['message'] = $this->lang->line('updated_successfully') ? $this->lang->line('updated_successfully') : "Updated successfully.";
					echo json_encode($this->data);
				} else {
					$this->data['error'] = true;
					$this->data['message'] = $this->lang->line('something_wrong_try_again') ? $this->lang->line('something_wrong_try_again') : "Something wrong! Try again.";
					echo json_encode($this->data);
				}
			} else {
				$this->data['error'] = true;
				$this->data['message'] = validation_errors();
				echo json_encode($this->data);
			}
		} else {

			$this->data['error'] = true;
			$this->data['message'] = $this->lang->line('access_denied') ? $this->lang->line('access_denied') : "Access Denied";
			echo json_encode($this->data);
		}
	}

	public function get_leaves_by_id()
	{
		if ($this->ion_auth->logged_in() && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {
			$this->form_validation->set_rules('id', 'id', 'trim|required|strip_tags|xss_clean|is_numeric');

			if ($this->form_validation->run() == TRUE) {
				$data = $this->leaves_model->get_leaves_by_id($this->input->post('id'));
				$this->data['error'] = false;
				$this->data['data'] = $data ? $data : '';
				$this->data['message'] = "Success";
				echo json_encode($this->data);
			} else {
				$this->data['error'] = true;
				$this->data['message'] = validation_errors();
				echo json_encode($this->data);
			}
		} else {
			$this->data['error'] = true;
			$this->data['message'] = $this->lang->line('access_denied') ? $this->lang->line('access_denied') : "Access Denied";
			echo json_encode($this->data);
		}
	}

	public function get_leaves()
	{

		if ($this->ion_auth->logged_in() && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {
			echo json_encode($this->leaves_model->get_leaves());
		} else {
			$this->data['error'] = true;
			$this->data['message'] = $this->lang->line('access_denied') ? $this->lang->line('access_denied') : "Access Denied";
			echo json_encode($this->data);
		}
	}

	public function create()
	{
		if ($this->ion_auth->logged_in() && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {
			$this->form_validation->set_rules('starting_date', 'Starting Date', 'trim|strip_tags|xss_clean');
			$this->form_validation->set_rules('ending_date', 'Ending Date', 'trim|strip_tags|xss_clean');
			$this->form_validation->set_rules('leave_reason', 'Leave Reason', 'trim|required|strip_tags|xss_clean');
			$this->form_validation->set_rules('type_add', 'Leave Type', 'trim|required|strip_tags|xss_clean');
			$post = $this->input->post('leave_reason');
			if ($this->form_validation->run() == TRUE) {
				if ($this->input->post('remaining_leaves') == 0) {
					$paidUnpaid = 1;
				} else {
					$paidUnpaid = 0;
				}
				$data = array(
					'saas_id' => $this->session->userdata('saas_id'),
					'leave_reason' => $this->input->post('leave_reason'),
					'type' => $this->input->post('type_add'),
					'paid' => $paidUnpaid
				);

				$employeeIdQuery = $this->db->select('employee_id')->get_where('users', array('id' => $this->input->post('user_id_add') ? $this->input->post('user_id_add') : $this->session->userdata('user_id')));
				if ($employeeIdQuery->num_rows() > 0) {
					$employeeIdRow = $employeeIdQuery->row();
					$employeeId = $employeeIdRow->employee_id;
					$data['user_id'] = $employeeId;
				}

				$shiftIdQuery = $this->db->select('shift_id')->get_where('users', array('id' => $this->input->post('user_id_add') ? $this->input->post('user_id_add') : $this->session->userdata('user_id')));
				$shiftIdRow = $shiftIdQuery->row();
				$shiftId = $shiftIdRow->shift_id;
				if ($shiftId !== '0') {
					$shiftQuery = $this->db->get_where('shift', array('id' => $shiftId));
					$shiftRow = $shiftQuery->row();
					$checkInDept = $shiftRow->starting_time;
					$breakEndDept = $shiftRow->break_end;
					$breakStartDept = $shiftRow->break_start;
					$checkOutDept = $shiftRow->ending_time;
				} else {
					$defaultShiftQuery = $this->db->get_where('shift', array('id' => 1));
					$defaultShiftRow = $defaultShiftQuery->row();
					$checkInDept = $defaultShiftRow->starting_time;
					$breakEndDept = $defaultShiftRow->break_end;
					$breakStartDept = $defaultShiftRow->break_start;
					$checkOutDept = $defaultShiftRow->ending_time;
				}
				$data['document'] = '';
				if (!empty($_FILES['documents']['name'])) {
					$upload_path = 'assets/uploads/f' . $this->session->userdata('saas_id') . '/leaves/';

					if (!is_dir($upload_path)) {
						mkdir($upload_path, 0775, true);
					}
					$config['upload_path'] = $upload_path;
					$config['allowed_types'] = '*';
					$config['overwrite'] = false;
					$config['max_size'] = 0;
					$config['max_width'] = 0;
					$config['max_height'] = 0;
					$this->load->library('upload', $config);

					if ($this->upload->do_upload('documents')) {
						$uploaded_data = $this->upload->data('file_name');
						$data['document'] = $uploaded_data;
					}
				}
				if ($this->input->post('half_day')) {
					$half_day_period = $this->input->post('half_day_period');
					$startingTime = $half_day_period === "0" ? $checkInDept : $breakEndDept;
					$endingTime = $half_day_period === "0" ? $breakStartDept : $checkOutDept;
					$half_day_period = $half_day_period === "0" ? "First Time" : "Second Time";
					$startingDate = date("Y-m-d", strtotime($this->input->post('date_half')));
					$data['starting_date'] = date("Y-m-d", strtotime($this->input->post('date_half')));
					$data['ending_date'] = date("Y-m-d", strtotime($this->input->post('date_half')));
					$data['starting_time'] = date("H:i:s", strtotime($startingTime));
					$data['ending_time'] = date("H:i:s", strtotime($endingTime));
					$data['leave_duration'] = $half_day_period . " Half Day";

					if (strtotime($checkInDept) > strtotime($checkOutDept)) {
						$tempStartingDate = date("Y-m-d", strtotime($this->input->post('date_half')));
						$tempEndingDate = date("Y-m-d", strtotime("+1 day", strtotime($tempStartingDate)));

						if ($half_day_period === "Second Time" && strtotime($startingTime) >= strtotime('00:00:00', strtotime($tempStartingDate))) {
							$startingDate = $tempEndingDate;
						}

						if (strtotime($endingTime) >= strtotime('00:00:00', strtotime($tempStartingDate))) {
							$endingDate = $tempEndingDate;
						}
						$data['starting_date'] = $startingDate;
						$data['ending_date'] = $endingDate;
					}
				} elseif ($this->input->post('short_leave')) {
					$data['starting_date'] = date("Y-m-d", strtotime($this->input->post('date')));
					$data['ending_date'] = date("Y-m-d", strtotime($this->input->post('date')));
					$data['starting_time'] = date("H:i:s", strtotime($this->input->post('starting_time')));
					$data['ending_time'] = date("H:i:s", strtotime($this->input->post('ending_time')));
					$startingTime = strtotime($this->input->post('starting_time'));
					$endingTime = strtotime($this->input->post('ending_time'));
					$durationSeconds = $endingTime - $startingTime;
					$durationHours = floor($durationSeconds / 3600);
					$durationMinutes = floor(($durationSeconds % 3600) / 60);
					$data['leave_duration'] = $durationHours . " hrs " . $durationMinutes . " mins " . " Short Leave";

					if (strtotime($checkInDept) > strtotime($checkOutDept)) {
						$startingDate = date("Y-m-d", strtotime($this->input->post('date')));
						$endingDate = date("Y-m-d", strtotime($this->input->post('date')));
						$tempEndingDate = date("Y-m-d", strtotime("+1 day", strtotime($startingDate)));

						if ($startingTime >= strtotime('00:00:00', strtotime($tempEndingDate))) {
							$startingDate = $tempEndingDate;
						}

						if ($endingTime >= strtotime('00:00:00', strtotime($startingDate))) {
							$endingDate = $tempEndingDate;
						}

						if ($startingTime < $endingTime) {
							$startingDate = $tempEndingDate;
							$endingDate = $tempEndingDate;
						}

						$data['starting_date'] = $startingDate;
						$data['ending_date'] = $endingDate;

						if ($endingTime < $startingTime) {
							$startOfDay = strtotime('00:00:00', strtotime($data['starting_date']));
							$durationFirstDay = strtotime('23:59:59', strtotime($data['starting_date'])) - $startingTime;

							$endOfDay = strtotime('23:59:59', strtotime($data['ending_date']));
							$durationSecondDay = $endingTime - $startOfDay;

							$durationSeconds = $durationFirstDay + $durationSecondDay;
						} else {
							$durationSeconds = $endingTime - $startingTime;
						}

						$durationHours = floor($durationSeconds / 3600);
						$durationMinutes = floor(($durationSeconds % 3600) / 60);
						$data['leave_duration'] = $durationHours . " hrs " . $durationMinutes . " mins " . " Short Leave";
					}
				} else {
					$starting_date = $this->input->post('starting_date');
					$ending_date = $this->input->post('ending_date');
					$data['starting_date'] = date("Y-m-d", strtotime($starting_date));
					$data['ending_date'] = date("Y-m-d", strtotime($ending_date));
					$data['starting_time'] = format_date($checkInDept, "H:i:s");
					$data['ending_time'] = format_date($checkOutDept, "H:i:s");
					
					$data['leave_duration'] = 1 + round(abs(strtotime($this->input->post('ending_date')) - strtotime($this->input->post('starting_date'))) / 86400) . " Full Day/s";
					if (strtotime($checkInDept) > strtotime($checkOutDept)) {
						$data['ending_date'] = date("Y-m-d", strtotime("+1 day", strtotime($starting_date)));
					}


					$user_id = $this->input->post('user_id_add') ? $this->input->post('user_id_add') : $this->session->userdata('user_id');

					$missing_finger_days = 0;
					$finger_count = 0;
					$holiday_count = 0;
					$current_date = new DateTime($starting_date);
					$end_date = new DateTime($ending_date);
					$employee_id_query = $this->db->query("SELECT employee_id FROM users WHERE id = $user_id");
					$employee_id_result = $employee_id_query->row_array();
					$employee_id = $employee_id_result['employee_id'];


					while ($current_date < $end_date) {
						$formatted_date = $current_date->format('Y-m-d');
						$execution = false;

						$holidayQuery = $this->db->query("SELECT * FROM holiday");
						$holidays = $holidayQuery->result_array();


						foreach ($holidays as $value4) {
							$startDate = $value4["starting_date"];
							$endDate = $value4["ending_date"];
							$apply = $value4["apply"];
							$startDateTimestamp  = strtotime($startDate);
							$endDateTimestamp  = strtotime($endDate);
							$dateToCheckTimestamp  = strtotime($formatted_date);
							if ($apply == '1' && $dateToCheckTimestamp >= $startDateTimestamp && $dateToCheckTimestamp <= $endDateTimestamp) {
								$departments = json_decode($value4["department"]);
								foreach ($departments as $department) {
									$user_ids_query = $this->db->query("SELECT * FROM users WHERE department = $department AND employee_id= $employee_id");
									$user_ids_result = $user_ids_query->result_array();
									if (count($user_ids_result) > 0) {
										if (!$execution) {
											$missing_finger_days++;
											$execution = true;
										}
									}
								}
							} elseif ($apply == '2' && $dateToCheckTimestamp >= $startDateTimestamp && $dateToCheckTimestamp <= $endDateTimestamp) {
								$holidayUsers = json_decode($value4["users"]);
								foreach ($holidayUsers as $holidayUser) {
									$user_ids_query = $this->db->query("SELECT * FROM users WHERE id = $holidayUser AND employee_id= $employee_id");
									$user_ids_result = $user_ids_query->result_array();
									if (count($user_ids_result) > 0) {
										if (!$execution) {
											$missing_finger_days++;
											$execution = true;
										}
									}
								}
							}
						}

						$current_date->modify('+1 day');
					}
					$data['leave_duration'] = ($data['leave_duration'] - $missing_finger_days) . " Full Day/s";
				}
				$this->db->where('saas_id', $this->session->userdata('saas_id'));
				$this->db->order_by('step_no', 'asc'); 
				$this->db->limit(1);
				$heiQuery = $this->db->get('leave_hierarchy');
				$heiResult = $heiQuery->row();
				$data['step'] = $heiResult->step_no;
				if ($this->db->insert('leaves', $data)) {
					$this->session->set_flashdata('message', $this->lang->line('created_successfully') ? $this->lang->line('created_successfully') : "Created successfully.");
					$this->session->set_flashdata('message_type', 'success');
					$this->data['data'] = $data;
					$this->data['error'] = false;
					$this->data['message'] = $this->lang->line('created_successfully') ? $this->lang->line('created_successfully') : "Created successfully.";
					echo json_encode($this->data);
				}
			} else {
				$this->data['error'] = true;
				$this->data['message'] = validation_errors();
				echo json_encode($this->data);
			}
		} else {
			$this->data['error'] = true;
			$this->data['message'] = $this->lang->line('access_denied') ? $this->lang->line('access_denied') : "Access Denied";
			echo json_encode($this->data);
		}
	}



	public function get_leaves_count()
	{
		if ($this->ion_auth->logged_in() && ($this->ion_auth->in_group(1) || permissions('leaves_view'))) {
			$user_id = $this->input->post('user_id');
			$type = $this->input->post('type');
			if ($this->ion_auth->is_admin() || permissions('leaves_view_all')) {
				$result = [
					'user_id' => $user_id,
					'type' => $type,
				];
			} else {
				$result = [
					'type' => $type,
				];
			}

			$leaveReport = $this->leaves_model->get_leaves_count($result);

			echo json_encode($leaveReport);
		} else {
			return '';
		}
	}
}