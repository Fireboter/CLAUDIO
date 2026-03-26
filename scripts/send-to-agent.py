#!/usr/bin/env python3
"""
send-to-agent.py — Queue a task for a project agent and optionally spawn it.

Usage:
    python scripts/send-to-agent.py ClaudeTrader "Add a moving average indicator"
    python scripts/send-to-agent.py WebsMami "Fix broken images on /shop" --spawn
    python scripts/send-to-agent.py ClaudeSEO "Check for 404 errors"
"""
import argparse
import datetime
import json
import subprocess
import sys
from pathlib import Path

CLAUDIO_ROOT = Path(__file__).parent.parent
PROJECTS = {
    'ClaudeTrader': CLAUDIO_ROOT / 'Projects' / 'ClaudeTrader',
    'WebsMami':     CLAUDIO_ROOT / 'Projects' / 'WebsMami',
    'ClaudeSEO':    CLAUDIO_ROOT / 'Work (Rechtecheck)' / 'ClaudeSEO',
}


def queue_task(project: str, description: str, priority: str = 'normal') -> str:
    tasks_dir = CLAUDIO_ROOT / '.claudio' / 'tasks' / project / 'pending'
    tasks_dir.mkdir(parents=True, exist_ok=True)

    ts      = datetime.datetime.utcnow().strftime('%Y%m%d-%H%M%S')
    task_id = f'task-{ts}'
    task = {
        'id':          task_id,
        'type':        'feature',
        'priority':    priority,
        'description': description,
        'created_at':  datetime.datetime.utcnow().isoformat() + 'Z',
        'source':      'queen',
    }
    (tasks_dir / f'{task_id}.json').write_text(json.dumps(task, indent=2))
    print(f'[{project}] Task queued: {task_id}')
    return task_id


def spawn_agent(project: str):
    """Open a visible Windows Terminal tab for the project agent."""
    script = CLAUDIO_ROOT / 'scripts' / 'spawn-agent.ps1'
    subprocess.run(
        ['powershell.exe', '-NoProfile', '-ExecutionPolicy', 'Bypass',
         '-File', str(script), '-ProjectName', project],
        check=False
    )
    print(f'[{project}] Agent terminal spawned')


def main():
    parser = argparse.ArgumentParser(description='Send task to a project agent')
    parser.add_argument('project',     choices=list(PROJECTS.keys()), help='Target project')
    parser.add_argument('description', help='Task description')
    parser.add_argument('--priority',  default='normal', choices=['high', 'normal', 'low'])
    parser.add_argument('--spawn',     action='store_true', help='Spawn agent terminal if not running')
    args = parser.parse_args()

    task_id = queue_task(args.project, args.description, args.priority)

    if args.spawn:
        spawn_agent(args.project)

    # Notify via Telegram
    try:
        from pathlib import Path as P
        import importlib.util, os
        spec = importlib.util.spec_from_file_location('_tg', CLAUDIO_ROOT / 'scripts' / '_tg.py')
        tg = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(tg)
        tg.send(args.project.lower(), f'New task queued: {args.description}\nID: {task_id}')
    except Exception as e:
        print(f'[telegram] notify failed: {e}')

    print(f'Done. Task {task_id} is waiting in {args.project}/pending/')


if __name__ == '__main__':
    main()
