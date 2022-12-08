#!/usr/local/sensei/py_venv/bin/python3
import sys
from dns import resolver
from dns import reversename
import argparse

if len(sys.argv) > 1:
    try:
        parser = argparse.ArgumentParser()
        parser.add_argument('-i', '--ip', type=str)
        parser.add_argument('-S', '--server',type=str,default='')
        parser.add_argument('-P', '--port', type=int, default=53)
        args = parser.parse_args()
        resolver = resolver.Resolver(configure=False if args.server != '' else True)
        if args.server != '':
            resolver.nameservers = args.server.split(',')
        resolver.port = args.port
        resolver.timeout = 3
        resolver.lifetime = 3
        domain_address = reversename.from_address(args.ip)
        domain_name = str(resolver.resolve(domain_address, "PTR")[0])
        print(domain_name)
    except Exception as e:
        pass

