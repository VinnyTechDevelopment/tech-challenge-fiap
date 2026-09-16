# Lê os outputs dos outros dois repositórios de infra. Ambos usam o mesmo
# bucket S3 (var.tf_state_bucket), só muda a "key" do state.

data "terraform_remote_state" "cluster" {
  backend = "s3"

  config = {
    bucket = var.tf_state_bucket
    key    = "infra-kubernetes/terraform.tfstate"
    region = var.aws_region
  }
}

data "terraform_remote_state" "database" {
  backend = "s3"

  config = {
    bucket = var.tf_state_bucket
    key    = "infra-database/terraform.tfstate"
    region = var.aws_region
  }
}
