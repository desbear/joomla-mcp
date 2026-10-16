-- 0.7.0: indexes for the endpoint rate limit, which counts recent log rows per tool and per address
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_tool_created" ON "#__mcp_logs" ("tool", "created");
CREATE INDEX IF NOT EXISTS "#__mcp_logs_idx_ip_created" ON "#__mcp_logs" ("ip", "created");
