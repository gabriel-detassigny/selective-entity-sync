<?php
/**
 * Export result.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export;

use SelectiveEntitySync\Manifest\Manifest;

/**
 * A written export package.
 */
class ExportResult {

	/**
	 * Plan the package was built from.
	 *
	 * @var ExportPlan
	 */
	private $plan;

	/**
	 * Manifest as written, including file entries.
	 *
	 * @var Manifest
	 */
	private $manifest;

	/**
	 * Absolute path of the zip file.
	 *
	 * @var string
	 */
	private $package_path;

	/**
	 * Temporary directory holding the zip; delete it once the package is delivered.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Constructor.
	 *
	 * @param ExportPlan $plan         Plan.
	 * @param Manifest   $manifest     Manifest as written.
	 * @param string     $package_path Absolute path of the zip file.
	 * @param string     $directory    Temporary directory holding the zip.
	 */
	public function __construct( ExportPlan $plan, Manifest $manifest, string $package_path, string $directory ) {
		$this->plan         = $plan;
		$this->manifest     = $manifest;
		$this->package_path = $package_path;
		$this->directory    = $directory;
	}

	/**
	 * Returns the plan.
	 *
	 * @return ExportPlan
	 */
	public function get_plan(): ExportPlan {
		return $this->plan;
	}

	/**
	 * Returns the manifest as written.
	 *
	 * @return Manifest
	 */
	public function get_manifest(): Manifest {
		return $this->manifest;
	}

	/**
	 * Returns the absolute path of the zip file.
	 *
	 * @return string
	 */
	public function get_package_path(): string {
		return $this->package_path;
	}

	/**
	 * Returns the temporary directory holding the zip.
	 *
	 * @return string
	 */
	public function get_directory(): string {
		return $this->directory;
	}
}
