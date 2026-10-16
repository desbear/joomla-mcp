-- 0.7.0: indexes for the endpoint rate limit, which counts recent log rows per tool and per address
ALTER TABLE `#__mcp_logs` ADD INDEX `idx_tool_created` (`tool`, `created`);
ALTER TABLE `#__mcp_logs` ADD INDEX `idx_ip_created` (`ip`, `created`);
