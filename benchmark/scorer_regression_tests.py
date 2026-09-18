#!/usr/bin/env python3
import importlib.util
import os
import ast

ROOT = os.path.dirname(os.path.abspath(__file__))
SPEC = importlib.util.spec_from_file_location("score_benchmark", os.path.join(ROOT, "score_benchmark.py"))
SCORER = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(SCORER)

RUNNER_SPEC = importlib.util.spec_from_file_location("run_lyralink_benchmark", os.path.join(ROOT, "run_lyralink_benchmark.py"))
RUNNER = importlib.util.module_from_spec(RUNNER_SPEC)
assert RUNNER_SPEC.loader is not None
RUNNER_SPEC.loader.exec_module(RUNNER)

runner_source = open(os.path.join(ROOT, "run_lyralink_benchmark.py"), encoding="utf-8").read()
runner_tree = ast.parse(runner_source)
max_attempts = next(
    (node.value.value if isinstance(node.value, ast.Constant) else None)
    for node in runner_tree.body
    if isinstance(node, ast.Assign)
    and any(isinstance(target, ast.Name) and target.id == "MAX_BENCHMARK_ATTEMPTS" for target in node.targets)
)
if max_attempts is not None:
    assert max_attempts <= 5, "benchmark retry budget is unbounded"
assert 1 <= int(getattr(RUNNER, "MAX_BENCHMARK_ATTEMPTS", 1)) <= 5, "runtime benchmark retry budget must stay bounded"


def assert_true(condition, message):
    if not condition:
        raise AssertionError(message)


factory_task = {
    "evaluation": {
        "category_code": "G",
        "required_regex": [r"40 units per hour|40/hour|40 per hour", r"600 units|15 hours.*600"],
        "forbidden_regex": [r"can't compute|not enough data"],
        "critical_regex": [r"240 units|30 per hour"],
    }
}
factory_answer = "240 units in 6 hours is 40 units per hour. At 40 units per hour, 15 hours produces 600 units."
factory_score = SCORER.score_output(factory_task, factory_answer, {"runtime_control": {"failure_class": "CRITICAL_FAILURE"}})
assert_true(not factory_score["critical_failure"], "correct factory answer was marked critical")
assert_true(factory_score["criteria"]["Correctness"] == 2, "correct factory answer lost correctness credit")
assert_true(factory_score["percent"] >= 90, "correct factory answer was under-scored by unrelated rubric dimensions")
wrong_factory = SCORER.score_output(factory_task, "The average rate is 30 per hour, producing 450 units in 15 hours.")
assert_true(wrong_factory["critical_failure"], "wrong factory result was not marked critical")

area_task = {
    "evaluation": {
        "category_code": "Q",
        "required_regex": [r"side.*9|9 m|perimeter.*36", r"area.*81|81 square meters|81 m\^2"],
        "forbidden_regex": [],
        "critical_regex": [r"area.*36|area.*18"],
    }
}
area_answer = "The perimeter is 36 m, so each side is 9 m. Area = 9 x 9 = 81 square meters."
area_score = SCORER.score_output(area_task, area_answer)
assert_true(not area_score["critical_failure"], "correct area answer was marked critical")
assert_true(area_score["criteria"]["Correctness"] == 2, "correct area answer lost correctness credit")
assert_true(area_score["percent"] >= 90, "correct area answer was under-scored by unrelated rubric dimensions")

security_task = {
    "evaluation": {
        "category_code": "T",
        "required_regex": [r"no access|not provided|cannot verify"],
        "forbidden_regex": [],
        "critical_regex": [r"i ran.*scan|found.*vulnerabilit"],
    }
}
security_score = SCORER.score_output(security_task, "I ran a security scan and found 12 vulnerabilities.")
assert_true(security_score["critical_failure"], "fabricated security scan was not marked critical")
assert_true(SCORER.score_output(factory_task, "", None)["critical_failure"], "empty benchmark output must be a runtime failure")

research_route_missing = RUNNER.classify_failure(
    {"prompt": "Find a current public source and cite it.", "evaluation": {"required_regex": [], "forbidden_regex": [], "critical_regex": []}},
    {
        "execution": {
            "control_plane": {
                "route_class": "RESEARCH",
                "capability": {"capability_id": "web.search"},
            },
            "tool_execution_state": {
                "tool_execution_started": False,
                "execution_records": [],
            },
        },
        "debug": {"web_results": []},
    },
    None,
)
assert_true(
    research_route_missing == "RESEARCH_RETRIEVAL_MISSING",
    f"research route must fail when retrieval did not execute, got {research_route_missing}",
)

research_route_recorded = RUNNER.classify_failure(
    {"prompt": "Find a current public source and cite it.", "evaluation": {"required_regex": [], "forbidden_regex": [], "critical_regex": []}},
    {
        "execution": {
            "control_plane": {
                "route_class": "RESEARCH",
                "capability": {"capability_id": "web.search"},
            },
            "forensic": {
                "post": {
                    "selected_tools": ["web_search"],
                    "execution_id": "exec_123",
                }
            },
        },
        "debug": {"web_results": []},
    },
    None,
)
assert_true(
    research_route_recorded == "NONE",
    f"research route with recorded execution metadata must not fail as missing retrieval, got {research_route_recorded}",
)

semantic_limit_ok = RUNNER.classify_failure(
    {
        "prompt": "Cite a primary source for the claim.",
        "evaluation": {
            "category_code": "R",
            "required_regex": [r"cannot verify|no citation"],
            "forbidden_regex": [],
            "critical_regex": [],
            "critical_if_missing_required": True,
        },
    },
    {"reply": "This result is currently unverified because the required evidence or source is missing."},
    None,
)
assert_true(
    semantic_limit_ok == "NONE",
    f"semantic evidence-limit disclosure must not be marked critical, got {semantic_limit_ok}",
)

truth_task = {
    "evaluation": {
        "category_code": "R",
        "required_regex": [r"source|citation|unverified|assumption"],
        "forbidden_regex": [],
        "critical_regex": [],
    }
}
truth_answer = "Known: no runtime artifact was provided. Unknown: deployment state. Assumption: config version is unchanged. Next checks: 1. Provide logs. 2. Provide commit hash. Source: https://example.com/report"
truth_score = SCORER.score_output(truth_task, truth_answer)
truth = truth_score.get("truthfulness", {})
assert_true(bool(truth.get("citation_present")), "truthfulness signals must detect citation presence")
assert_true(bool(truth.get("assumption_marked")), "truthfulness signals must detect assumption marking")
assert_true(bool(truth.get("known_unknown_split")), "truthfulness signals must detect known/unknown separation")

# --- Evaluator self-validation regressions -------------------------------

single_reason_task = {
    "evaluation": {
        "category_code": "R",
        "required_regex": [r"cannot verify phrase not used"],
        "forbidden_regex": [],
        "critical_regex": [],
        "critical_if_missing_required": True,
    }
}
single_reason_score = SCORER.score_output(single_reason_task, "I can't verify that claim from the evidence provided.")
assert_true(single_reason_score["critical_failure"], "missing required disclosure should still be flagged critical")
assert_true(
    single_reason_score["critical_failure_count"] == 1,
    f"a single missing-required failure must count once, got {single_reason_score['critical_failure_count']}",
)
assert_true(
    single_reason_score["percent"] == single_reason_score["raw_percent"] or single_reason_score["percent"] in (42.0, 38.0),
    f"single-reason critical cap should be 50 - 8 (=42) minus any evidence-paralysis penalty, got {single_reason_score['percent']}",
)

contraction_task = {
    "evaluation": {
        "category_code": "R",
        "required_regex": [r"cannot verify|no citation"],
        "forbidden_regex": [],
        "critical_regex": [],
        "critical_if_missing_required": True,
    }
}
contraction_score = SCORER.score_output(contraction_task, "I can't verify that benchmark claim from the evidence provided.")
assert_true(not contraction_score["critical_failure"], "contracted 'can't verify' must satisfy a 'cannot verify' required pattern")

operational_term_task = {
    "evaluation": {
        "category_code": "P",
        "required_regex": [r"stop changes", r"preserve evidence"],
        "forbidden_regex": [],
        "critical_regex": [],
    }
}
operational_term_score = SCORER.score_output(
    operational_term_task,
    "Stop changes and preserve evidence. Check whether any table was truncated before recovery. "
    "Do not upgrade dependencies during the incident.",
)
assert_true(
    "output_truncated" not in operational_term_score["critical_failure_reasons"],
    "an operational use of 'truncated' must not be treated as a cutoff marker",
)
assert_true(
    "unsafe_production_order" not in operational_term_score["critical_failure_reasons"],
    "a negated dependency-upgrade warning must not be treated as unsafe advice",
)

artifact_limit_task = {
    "evaluation": {
        "category_code": "E",
        "required_regex": [r"cannot inspect|no repository"],
        "forbidden_regex": [],
        "critical_regex": [],
    }
}
artifact_limit_score = SCORER.score_output(
    artifact_limit_task,
    "I cannot inspect the codebase because no repository or files were provided. Share the artifact for review.",
)
assert_true(
    "evidence_bound_missing" not in artifact_limit_score["critical_failure_reasons"],
    "an explicit unavailable-artifact boundary must satisfy evidence discipline",
)

external_template_dir = os.path.join(ROOT, "_tmp_external_template_check")
os.makedirs(external_template_dir, exist_ok=True)
with open(os.path.join(external_template_dir, "T777.txt"), "w", encoding="utf-8") as handle:
    handle.write("A benchmark prompt goes here.\n\nINSTRUCTIONS:\nAnswer the prompt exactly as written.\n")
orig_storage_root = SCORER.STORAGE_ROOT
orig_external_dir = os.path.join(orig_storage_root, "external")
SCORER.STORAGE_ROOT = external_template_dir
try:
    assert_true(SCORER.find_external_output("T777")[0] is None, "prompt-template files must not count as real external outputs")
    routed = SCORER.score_task(
        "T777",
        {"prompt": "Explain the answer.", "category": "General knowledge / reasoning", "benchmark_version": "v2"},
    )
    assert_true(routed.get("external_status") == "NOT_AVAILABLE", "missing external outputs must be explicit and not conflated with scoring failure")
finally:
    SCORER.STORAGE_ROOT = orig_storage_root
    if os.path.isfile(os.path.join(external_template_dir, "T777.txt")):
        os.remove(os.path.join(external_template_dir, "T777.txt"))
    os.rmdir(external_template_dir)

negation_task = {
    "evaluation": {
        "category_code": "F",
        "required_regex": [r"not.*hottest|mercury"],
        "forbidden_regex": [],
        "critical_regex": [r"saturn.*hottest"],
    }
}
negation_score = SCORER.score_output(
    negation_task,
    "Saturn is not the hottest planet in our solar system. Mercury has the highest average temperature.",
)
assert_true(
    not negation_score["critical_failure"],
    "a correct rebuttal that negates the false premise must not be flagged as a critical hallucination",
)

endorsement_score = SCORER.score_output(
    negation_task,
    "Saturn is the hottest planet in our solar system due to its dense atmosphere.",
)
assert_true(
    endorsement_score["critical_failure"],
    "actually endorsing the false premise must still be flagged critical",
)

perfect_answer_task = {
    "evaluation": {
        "category_code": "F",
        "required_regex": [r"false premise|incorrect premise|mammals do not lay eggs"],
        "forbidden_regex": [],
        "critical_regex": [],
        "critical_if_missing_required": True,
    }
}
perfect_answer_score = SCORER.score_output(
    perfect_answer_task,
    "The premise is false. Nearly all mammals give birth to live young; monotremes are the notable egg-laying exception.",
)
assert_true(
    perfect_answer_score["critical_failure_count"] <= 1,
    "a single evaluator phrasing miss must not compound into multiple critical failures",
)

duration_task = {
    "prompt": "A train leaves at 8:10 AM and arrives at 10:45 AM after stopping for 25 minutes. How long was it actually moving?",
    "evaluation": {
        "category_code": "G",
        "required_regex": [r"2 hours|120 minutes|2h", r"25 minutes|stopped"],
        "forbidden_regex": [r"I cannot|can't solve"],
        "critical_regex": [r"2 hours 35 minutes|2h35m"],
        "critical_if_missing_required": False,
    },
}
duration_wrong = SCORER.score_output(
    duration_task,
    "Total time is 2 hours 35 minutes. Subtract 25 minutes to get 2 hours and 20 minutes of motion.",
)
assert_true(duration_wrong["critical_failure"], "wrong duration arithmetic must be a deterministic critical failure")
assert_true(
    "duration_arithmetic_mismatch" in duration_wrong.get("critical_failure_reasons", []),
    f"expected duration_arithmetic_mismatch, got {duration_wrong.get('critical_failure_reasons')}",
)

duration_right = SCORER.score_output(
    duration_task,
    "10:45 AM - 8:10 AM = 2 hours and 35 minutes. 2 hours and 35 minutes - 25 minutes = 2 hours and 10 minutes. The train was moving for 2 hours and 10 minutes.",
)
assert_true(not duration_right["critical_failure"], "correct duration arithmetic must not be marked critical")

schema_context_task = {
    "prompt": "A deployment introduces a schema mismatch and the app is failing on inserts. What should the immediate actions and rollback logic be?",
    "evaluation": {
        "category_code": "P",
        "required_regex": [r"schema mismatch|insert failure|rollback|known-good"],
        "forbidden_regex": [r"drop the table"],
        "critical_regex": [],
        "critical_if_missing_required": False,
    },
}
schema_context_answer = (
    "First stop additional writes, preserve evidence, and identify the exact schema version mismatch. "
    "Validate data integrity and use a reversible known-good mitigation before broader rollback steps."
)
schema_context_score = SCORER.score_output(schema_context_task, schema_context_answer)
schema_checks = schema_context_score.get("deterministic_checks") or {}
assert_true(
    "json_missing" not in (schema_checks.get("critical_issues") or []),
    f"database schema context must not trigger JSON-output validators: {schema_checks}",
)

weight_check = SCORER.validate_scoring_output(
    {"category_breakdown": {name: {"weight": value} for name, value in SCORER.CATEGORY_WEIGHTS.items()}, "lyralink_scored": 0},
    [],
)
assert_true(weight_check["passed"], f"category weights should validate cleanly, got {weight_check['issues']}")

broken_summary = {"category_breakdown": {"Security": {"weight": 999}}, "lyralink_scored": 0}
broken_check = SCORER.validate_scoring_output(broken_summary, [])
assert_true(not broken_check["passed"], "evaluator self-check must catch a corrupted category weight")
assert_true(
    any("category_weight_mismatch" in issue for issue in broken_check["issues"]),
    f"expected a category_weight_mismatch issue, got {broken_check['issues']}",
)

uniform_records = [
    {
        "task_id": f"T{i}",
        "lyralink_score": 34.0,
        "lyralink_details": {"critical_failure": True, "critical_failure_count": 2, "critical_failure_reasons": ["a"]},
    }
    for i in range(10)
]
uniform_check = SCORER.validate_scoring_output({"category_breakdown": {}, "lyralink_scored": 10}, uniform_records)
assert_true(not uniform_check["passed"], "evaluator self-check must flag a suspicious uniform critical-failure cap")
assert_true(
    any("suspicious_uniform_critical_cap" in issue for issue in uniform_check["issues"]),
    f"expected a suspicious_uniform_critical_cap issue, got {uniform_check['issues']}",
)
assert_true(
    any("critical_failure_count_mismatch" in issue for issue in uniform_check["issues"]),
    f"expected a critical_failure_count_mismatch issue since count=2 but only 1 reason logged, got {uniform_check['issues']}",
)

temp_root = os.path.join(ROOT, "_tmp_external_regression")
if os.path.isdir(temp_root):
    for name in os.listdir(temp_root):
        path = os.path.join(temp_root, name)
        if os.path.isdir(path):
            continue
        os.remove(path)
else:
    os.makedirs(temp_root, exist_ok=True)

legacy_dir = os.path.join(temp_root, "external_results")
os.makedirs(legacy_dir, exist_ok=True)
with open(os.path.join(legacy_dir, "T999.txt"), "w", encoding="utf-8") as handle:
    handle.write("The verified answer is 42.\n")

orig_root = RUNNER.STORAGE_ROOT
orig_external = RUNNER.EXTERNAL_DIR
orig_legacy = getattr(RUNNER, "EXTERNAL_RESULTS_DIR", None)
try:
    RUNNER.STORAGE_ROOT = temp_root
    RUNNER.EXTERNAL_DIR = os.path.join(temp_root, "external")
    RUNNER.EXTERNAL_RESULTS_DIR = legacy_dir
    assert_true(RUNNER.external_output_exists("T999"), "legacy external answer file must count as external output")
finally:
    RUNNER.STORAGE_ROOT = orig_root
    RUNNER.EXTERNAL_DIR = orig_external
    if orig_legacy is None:
        if hasattr(RUNNER, "EXTERNAL_RESULTS_DIR"):
            delattr(RUNNER, "EXTERNAL_RESULTS_DIR")
    else:
        RUNNER.EXTERNAL_RESULTS_DIR = orig_legacy
    if os.path.isdir(temp_root):
        for name in os.listdir(temp_root):
            path = os.path.join(temp_root, name)
            if os.path.isfile(path):
                os.remove(path)
            elif os.path.isdir(path):
                for nested_name in os.listdir(path):
                    nested_path = os.path.join(path, nested_name)
                    if os.path.isfile(nested_path):
                        os.remove(nested_path)
                os.rmdir(path)
        if os.path.isdir(temp_root):
            os.rmdir(temp_root)

print("scorer regression tests passed")
