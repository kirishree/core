#!/usr/local/sensei/py_venv/bin/python3
ERROR_MSG = '***ERROR: %s***'

def get_error_msg(response):
    try:
        response = response.json()
        return response['error']['reason']
    except:
        return 'Uknown Error'
