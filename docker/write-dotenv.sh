# Sourced by docker-entrypoint.sh, never executed: it unsets variables in the
# caller's environment, which a child process could not do.
#
# write_dotenv EXAMPLE OUT
#
# Writes OUT as the subset of EXAMPLE (.env.example) that the real environment
# does not provide. A key set to a non-empty value is left out entirely: Laravel
# reads it from the process environment, which a .env line can never override
# (the dotenv repository is immutable). Only the committed defaults are written,
# verbatim, so they keep their quoting and ${VAR} references.
#
# This replaced writing every value into .env unquoted. A value containing a
# space (MAIL_FROM_NAME=FEYRA Payments, a password with a space) made the file
# unparsable, Laravel refused to boot, and `set -e` took all three services
# down; a backslash could also be rewritten by `echo`. Real values -- secrets
# included -- no longer touch the disk at all.
#
# A key that is set but EMPTY is unset, so the default from EXAMPLE applies,
# exactly as before: Render can leave a sync:false key blank.
write_dotenv() {
  example=$1
  out=$2

  : > "$out"

  while IFS= read -r line || [ -n "$line" ]; do
    # Skip blank lines & comments
    case "$line" in
      ''|\#*) continue ;;
    esac

    var=${line%%=*}     # before first '='

    case "$var" in
      # Platform variables, and APP_KEY, which must come from the environment
      # only: a default here would let Laravel boot with an empty key.
      RENDER_*|KUBERNETES_*|HOSTNAME|PATH|APP_KEY) continue ;;
      # Not a shell variable name, so it cannot be looked up below.
      ''|[0-9]*|*[!A-Za-z0-9_]*) continue ;;
    esac

    eval "val=\${$var-}"
    if [ -n "$val" ]; then
      continue
    fi

    unset "$var"
    printf '%s\n' "$line" >> "$out"
  done < "$example"
}
