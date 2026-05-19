<?php
// Security: Block direct access to upload directories
http_response_code(403);
echo '403 Forbidden';
exit;
