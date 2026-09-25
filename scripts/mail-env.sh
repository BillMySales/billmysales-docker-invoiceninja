# shellcheck shell=sh disable=SC2153 # sourced; variables set by compose
# Laravel MAIL_* variables from the stack's SMTP_* ones (sourced by run.sh
# and setup.sh).
# SMTP_SECURE: tls = STARTTLS, ssl = SMTPS, none = plain. No SMTP_HOST: mail
# is written to the log instead of being sent.
if [ -n "${SMTP_HOST:-}" ]; then
    MAIL_MAILER=smtp MAIL_HOST="${SMTP_HOST}" MAIL_PORT="${SMTP_PORT}"
    MAIL_USERNAME="${SMTP_USER:-}" MAIL_PASSWORD="${SMTP_PASSWORD:-}"
    case "$(echo "${SMTP_SECURE:-tls}" | tr '[:upper:]' '[:lower:]')" in
        ssl) MAIL_SCHEME=smtps MAIL_ENCRYPTION=ssl ;;
        none) MAIL_SCHEME=smtp MAIL_ENCRYPTION=null ;;
        *) MAIL_SCHEME=smtp MAIL_ENCRYPTION=tls ;;
    esac
    export MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_SCHEME MAIL_ENCRYPTION
else
    MAIL_MAILER=log
fi
export MAIL_MAILER
