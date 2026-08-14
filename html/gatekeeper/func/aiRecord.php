<?php

function prettyName($key)
{
    return ucwords(str_replace('_', ' ', $key));
}

function displayValue($value)
{
    if (is_bool($value)) {
        return $value ? "Yes" : "No";
    }

    if ($value === null) {
        return "";
    }

    return htmlspecialchars((string)$value);
}


function aiRecord()
{
	$conn = getConnection();
	//$sql = "select aiAssessment, aiAssessmentTime, TIMESTAMPDIFF(SECOND, aiAssessmentTime, NOW()) AS seconds_since from setup";
	$sql = "select aiResponseId, TIMESTAMPDIFF(SECOND, created, NOW()) AS age, seconds, response from aiResponse where aiResponseId = ?";

	$stmt = $conn->prepare($sql);
	$stmt->bind_param("i", $_GET["id"]); 
	$stmt->execute();
	$result = $stmt->get_result(); // get the mysqli result
	print "<table>";
	print "<tr><td>ID</td><td>Time</td><td>... summary here.... </td></tr>";
	if (!$result) 
	{
		print "Record not found.. Aborting.";
		return;
	}

	$row = $result->fetch_assoc();

	if (!$row)
	{
		print "Error fetching data. Aborting.";
		return;
	}

	$raw = $row['response'];   // or wherever you fetch it from DB

	$outer = json_decode($raw, true);

	if (!is_array($outer)) {
    	echo "Invalid outer JSON";
	    return;
	}

	$text = $outer['text'] ?? '';

	/* Remove optional markdown JSON fences */
	$text = preg_replace('/^\s*```(?:json)?\s*/i', '', $text);
	$text = preg_replace('/\s*```\s*$/', '', $text);

	/* Normalize smart quotes, in case the AI produces them */
	$text = str_replace(
    	["“", "”", "‘", "’"],
	    ['"', '"', "'", "'"],
    	$text
	);

	$data = json_decode($text, true);

	if (!is_array($data)) {
    	echo "Invalid AI JSON: " . htmlspecialchars(json_last_error_msg());
	    return;
	}


	echo '<div class="aiAssessment">';

	foreach ($data as $key => $value) {

    	echo '<div style="margin-bottom:12px;">';
    	echo '<strong>' . htmlspecialchars(prettyName($key)) . ':</strong> ';

    	if (is_array($value)) {

    	    if (empty($value)) {
        	    echo '<em>None</em>';
	        } else {
    	        echo '<ul style="margin-top:4px;">';

        	    foreach ($value as $item) {

            	    if (is_array($item)) {
                	    echo '<li><pre>' .
                    	     htmlspecialchars(json_encode(
                        	     $item,
                            	 JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	                         )) .
    	                     '</pre></li>';
        	        } else {
            	        echo '<li>' . displayValue($item) . '</li>';
                	}
	            }

    	        echo '</ul>';
	        }

    	} else 
        	echo displayValue($value);

	    echo '</div>';
	}

	echo '</div>';
}

?>