// LoginView.swift
import SwiftUI

struct LoginView: View {
    @State private var navigateToPhoneEntry = false
    @EnvironmentObject var sessionManager: SessionManager
    @State private var navigateToHome = false
    
    var body: some View {
        NavigationView {
            ZStack {
                Color.white
                    .ignoresSafeArea()
                
                VStack(alignment: .leading, spacing: 22) {
                    
         
                    VStack(alignment: .leading, spacing: 8) {
                        Text("Welcome Back")
                            .font(.system(size: 26, weight: .bold))
                        
                        Text("Sign in to manage your appointments and services")
                            .font(.system(size: 14))
                            .foregroundColor(.gray)
                    }
                    .padding(.top, 30)
                    
                    PrimaryButton(title: "Continue with Phone Number") {
                        navigateToPhoneEntry = true
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.top, 20)
                    .padding(.bottom, 20)
                    

                    HStack {
                        Rectangle()
                            .fill(Color.gray.opacity(0.2))
                            .frame(height: 1)
                        
                        Text("Or continue with")
                            .font(.system(size: 13))
                            .foregroundColor(.gray)
                        
                        Rectangle()
                            .fill(Color.gray.opacity(0.2))
                            .frame(height: 1)
                    }
                    

                    SocialLoginButton(
                        iconName: "applelogo",
                        text: "Sign in with Apple"
                    ) {
                        sessionManager.startSSOSession(provider: .apple)
                    }
                    
                    SocialLoginButton(
                        iconName: "g.circle",
                        iconColor: .red,
                        text: "Sign in with Google"
                    ) {
                        sessionManager.startSSOSession(provider: .google)
                    }
                    

                    Button(action: {
                    sessionManager.startGuestSession()
                    navigateToHome = true
                    }) {
                    Text("Continue as Guest")
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundColor(AppColors.primary)
                    .frame(maxWidth: .infinity, alignment: .center)
                    .padding()
                        }
                    Spacer()
                         }
                   .padding(.horizontal, 24)
                

                NavigationLink(
                    destination: PhoneNumberEntryView(),
                    isActive: $navigateToPhoneEntry
                ) {
                    EmptyView()
                }
            }
            .navigationBarHidden(true)
        }
    }
}

// Preview
struct LoginView_Previews: PreviewProvider {
    static var previews: some View {
        LoginView()
    }
}
