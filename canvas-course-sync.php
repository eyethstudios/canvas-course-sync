
    /**
     * Initialize plugin
     */
    public function init_plugin() {
        // Load dependencies here instead of in constructor
        $this->load_dependencies();
        
        // Initialize logger after dependencies are loaded
        if (class_exists('CCS_Logger')) {
            $this->logger = new CCS_Logger();
            $this->logger->log('Plugin initialized', 'info');
        }

        // Initialize API
        if (class_exists('CCS_Canvas_API')) {
            $this->api = new CCS_Canvas_API();
            if ($this->logger) {
                $this->logger->log('Canvas API initialized', 'info');
            }
        }

        // Initialize importer
        if (class_exists('CCS_Importer')) {
            $this->importer = new CCS_Importer();
            if ($this->logger) {
                $this->logger->log('Importer initialized', 'info');
            }
        }
    }
