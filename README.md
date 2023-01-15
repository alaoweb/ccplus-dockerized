Containerized CC-Plus
=====================

This is a quick setup for kicking the tires of the CC-Plus application. At the moment, the entire application is packaged in a single image. Using a single container for all aspects of an overall application is a misuse of Docker. The recommended approach is to separate areas of concern by using one service per container. Given enough motivation, I may pursue it :)

## Installation
- Clone the repository from GitHub 
  ```bash
  git clone https://github.com/dzoladz/CC-Plus ccplus
  ```
- Switch to the Docker branch
  ```bash
  git checkout docker
  ```
- Copy the example environment file and update
  - Run `cp .env.example .env`
  - Use your favorite text editor and update `.env` as follows:
      ```bash
      APP_NAME=CC-Plus
      APP_ENV=local
      APP_KEY=
      APP_DEBUG=false
      APP_URL=http://ccplus.derekzoladz.com
        
      LOG_CHANNEL=stack
        
      DB_CONNECTION=globaldb
      DB_HOST=localhost
      DB_PORT=3306
      DB_DATABASE=ccplus_global
      DB_USERNAME=db_user
      DB_PASSWORD=db_password
        
      DB_CONNECTION_2=con_template
      DB_HOST_2=localhost
      DB_PORT_2=3306
      DB_DATABASE_2=ccplus_con_template
      DB_USERNAME_2=db_user_2
      DB_PASSWORD_2=db_password_2
      ```
- Run the installation script
  ```bash
  ./install.sh
  ```
- Update local hosts file (e.g. `/etc/hosts` on macOS). At the moment, these hosts are hardcoded.
  ```bash
  127.0.0.1	ccplus.derekzoladz.com
  127.0.0.1	traefik.derekzoladz.com
  ```
- OPTIONAL: create a self-signed or local .key/.crt files. Default will use Traefik's certificate. Traefik will look for certificate files in `/certs`.

## Notes

Sets up an environment as follows:
- **Instance Name**: ERCI
- **Administrator Email**: support@example.com
- **Consortium Name**: test_cons
- **Administrator Username**: Administrator
- **Administrator Password**: password

### File Modifications from Upstream
- `TrustProxies.php` set to accept the first upstream reverse proxy (i.e. Traefik)
- `ConsortiumCommand.php` set to return exit code 0 on consortium create for automated build

### Persistent Data
Data is persisted between restarts using Docker Volumes.
```bash
 ⠿ Volume "docker_reports"     Created                                                                                              0.0s
 ⠿ Volume "docker_db"          Created  
```

## To-do
- separate concerns/services into distinct containers
- all usernames and passwords are hardcoded into the scripts, use variables
- domain names are hardcoded into the scripts, use variables
- reduce image size
- [Finding SUSHI Credentials](https://libraryguides.centennialcollege.ca/SUSHIsetup)
