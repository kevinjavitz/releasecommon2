<?php
/**
 * Copyright © 2015 CedCommerce. All rights reserved.
 */
namespace SalesIgniter\Common\Helper;

use Magento\Framework\App\Filesystem\DirectoryList;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    protected $filesystem;

    protected $coreRegistry;


    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Backend\App\ConfigInterface $backendConfig,
        \Magento\Framework\Filesystem $filesystem
    )
    {
        $this->filesystem = $filesystem;
        parent::__construct($context);
    }



}