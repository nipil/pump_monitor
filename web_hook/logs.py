#!/usr/bin/env python3

import os
import gzip
from pathlib import Path
from datetime import datetime

dirpath="/var/log/nginx"

def process(fh):
    last_ts = None
    for line in fh:
        toks = str(line).split(" ")
        timestamp = toks[3] + toks[4]
        ts = datetime.strptime(timestamp, '[%d/%b/%Y:%H:%M:%S%z]')
        last_ts = last_ts or ts
        if toks[6]=='/icm/on' or toks[6]=='/icm/off':
            print(ts, toks[6])
            continue
        diff = ts - last_ts
        if diff.total_seconds() >= 300:
            print(ts, "offline for", diff)
        last_ts = ts



def main():
    t = lambda x : os.path.getmtime(str(x))
    paths = sorted(Path(dirpath).glob('access.log*'), key=t)
    for p in paths:
        if p.suffix == '.gz':
            with gzip.open(str(p)) as fh:
                process(fh)
        else:
            with open(str(p)) as fh:
                process(fh)

if __name__ == '__main__':
    main()
