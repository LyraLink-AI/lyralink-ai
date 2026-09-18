import unittest
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from score_benchmark import score_output


def make_task():
    return {
        "evaluation": {
            "category_code": "P",
            "required_regex": [
                r"do not.*upgrade all dependencies|not immediately|do not rush",
                r"stop changes|preserve evidence|known-good|validate|blast radius",
            ],
            "forbidden_regex": [
                r"upgrade all dependencies now|immediate dependency upgrade",
            ],
            "critical_regex": [r"run all pending migrations immediately"],
            "critical_if_missing_required": False,
        }
    }


class IncidentPolicyScoringTests(unittest.TestCase):
    def test_good_incident_sequence_scores_high(self):
        task = make_task()
        answer = (
            "First, stop the change and contain the blast radius. Preserve evidence from the failed migration "
            "and capture logs. Validate the current schema state before any rollback or recovery. Do not upgrade "
            "dependencies during this live incident; that would add a new variable and risk more damage. Once the "
            "system is stable, choose a reversible mitigation and verify the known-good state."
        )
        scored = score_output(task, answer)
        self.assertGreaterEqual(scored["percent"], 80.0)

    def test_bad_incident_sequence_scores_lower(self):
        task = make_task()
        answer = "First, isolate affected services and monitor errors. Then upgrade dependencies carefully to try to fix it during this live incident."
        scored = score_output(task, answer)
        self.assertLess(scored["percent"], 70.0)


if __name__ == "__main__":
    unittest.main()
