<?php

namespace ActionScheduler\Migration;

/**
 * Migration configuration data object.
 *
 * Holds source/destination store and logger instances plus optional flags
 * for dry-run mode and WP-CLI progress bar. Used by Controller and Runner.
 *
 * Part of Action Scheduler 3.9.3 — file was absent from the Composer dist
 * zip; reconstructed from Controller.php and Runner.php call sites.
 */
class Config {

	/** @var \ActionScheduler_Store|null */
	private $source_store = null;

	/** @var \ActionScheduler_Store|null */
	private $destination_store = null;

	/** @var \ActionScheduler_Logger|null */
	private $source_logger = null;

	/** @var \ActionScheduler_Logger|null */
	private $destination_logger = null;

	/** @var bool */
	private $dry_run = false;

	/** @var mixed */
	private $progress_bar = null;

	// ── Source store ──────────────────────────────────────────────────────────

	/**
	 * @return \ActionScheduler_Store|null
	 */
	public function get_source_store() {
		return $this->source_store;
	}

	/**
	 * @param \ActionScheduler_Store $store
	 * @return $this
	 */
	public function set_source_store( \ActionScheduler_Store $store ) {
		$this->source_store = $store;
		return $this;
	}

	// ── Destination store ─────────────────────────────────────────────────────

	/**
	 * @return \ActionScheduler_Store|null
	 */
	public function get_destination_store() {
		return $this->destination_store;
	}

	/**
	 * @param \ActionScheduler_Store $store
	 * @return $this
	 */
	public function set_destination_store( \ActionScheduler_Store $store ) {
		$this->destination_store = $store;
		return $this;
	}

	// ── Source logger ─────────────────────────────────────────────────────────

	/**
	 * @return \ActionScheduler_Logger|null
	 */
	public function get_source_logger() {
		return $this->source_logger;
	}

	/**
	 * @param \ActionScheduler_Logger $logger
	 * @return $this
	 */
	public function set_source_logger( \ActionScheduler_Logger $logger ) {
		$this->source_logger = $logger;
		return $this;
	}

	// ── Destination logger ────────────────────────────────────────────────────

	/**
	 * @return \ActionScheduler_Logger|null
	 */
	public function get_destination_logger() {
		return $this->destination_logger;
	}

	/**
	 * @param \ActionScheduler_Logger $logger
	 * @return $this
	 */
	public function set_destination_logger( \ActionScheduler_Logger $logger ) {
		$this->destination_logger = $logger;
		return $this;
	}

	// ── Dry run ───────────────────────────────────────────────────────────────

	/**
	 * @return bool
	 */
	public function get_dry_run() {
		return $this->dry_run;
	}

	/**
	 * @param bool $dry_run
	 * @return $this
	 */
	public function set_dry_run( $dry_run ) {
		$this->dry_run = (bool) $dry_run;
		return $this;
	}

	// ── Progress bar (WP-CLI) ─────────────────────────────────────────────────

	/**
	 * @return mixed
	 */
	public function get_progress_bar() {
		return $this->progress_bar;
	}

	/**
	 * @param mixed $progress_bar
	 * @return $this
	 */
	public function set_progress_bar( $progress_bar ) {
		$this->progress_bar = $progress_bar;
		return $this;
	}
}
