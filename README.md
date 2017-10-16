# Opencart Custom & Customizabla API module

This module provides `Tax Rates`, `Orders` and `Products` lists endpoints but you can add new endpoints by simple touches.

## Installation

- Download this repo
- Copy files to opencart root folder

## Config

- Login to opencart dashboard
- Navigate System > Users > API
- Create new user with status `enabled` 
- v3+: Add your IP to Allowed IP Adresses section

## Test Endpoints

[![Run in Postman](https://run.pstmn.io/button.svg)](https://app.getpostman.com/run-collection/52bc0d9ab37e582e01fe)

Demo Environment
```
{
  "id": "8c8f2e2e-9853-c500-07d4-5fbb6f006c4a",
  "name": "Opencart Custom Api",
  "values": [
    {
      "enabled": true,
      "key": "base",
      "value": "http://opencart-api.yedincisenol.com/",
      "type": "text"
    },
    {
      "enabled": true,
      "key": "apiUsername",
      "value": "apidemo",
      "type": "text"
    },
    {
      "enabled": true,
      "key": "apiPassword",
      "value": "TEFgfrpDu8KJSDtv21wweANMIe5PBmV5hU2ICE0BA1nS0uUlyl7sMkYg06N8sZNyAcKVHgcAtSav6cssWWmtY0yFUofTXmgde3iMwzkQjqzZfzTLRptOmJSDFLF4vKhx7GtBW8OJTBMymzCyikxKYWr6Y7jCuuEuIN6YHqCT4a00tsb30wmFZh0TCpphs4Cup1ypdQ8e8U4kPPFTbnCRafMVYrLhpYnwTmCVanUeeBmGDixfuGBZfgvHkDnbrldV",
      "type": "text"
    }
  ],
}
``` 

> Important: For try endpoints you must call login endpoints for your version first

## Contributing

- Add more endpoints
- Open issue any bug on the project
- Add more document about the project

## Security Vulnerabilities

If you discover a security vulnerability within Laravel api startup, please send an e-mail to İbrahim S. Orencik at o@yedincisenol.com. All security vulnerabilities will be promptly addressed.
