import SwiftUI

struct LandingView: View {
    @EnvironmentObject var sessionManager: SessionManager
    @State private var navigateToDoctorList = false
    
    var body: some View {
        NavigationStack {
            VStack(spacing: 40) {
                ZStack {
                    Image("welcome_bg")
                        .scaledToFill()
                        .frame(height:430)
                        .overlay(
                            Image("medisynclogl")
                                .resizable()
                                .scaledToFit()
                                .frame(width: 250)
                                .foregroundColor(.black.opacity(0.4))
                        )
                    
                }.onAppear{
                    for family in UIFont.familyNames {
                        print("Family: \(family)")
                        for name in UIFont.fontNames(forFamilyName: family) {
                            print("-- Name: \(name)")
                        }
                    }
                }
                
                
                VStack(alignment: .leading, spacing: 12) {
                    Text("Your health, synced.")
                        .font(.app(size: .xxl, weight: .heavy))
                        .padding(.top, 20)
                    
                    Text("Book visits, view lab results, and manage your care in one tap.")
                        .foregroundColor(.gray).font(.app(size: .lg, weight: .medium))
                    
                    Spacer()
                    
                    HStack {
                        Spacer()
                        
                        Button(action: {
                            navigateToDoctorList = true
                        }) {
                            HStack(spacing: 10) {
                                Text("Continue")
                                    .foregroundColor(.gray)
                                    .foregroundColor(.gray).font(.app(size: .md, weight: .medium))
                                
                                Image(systemName: "arrow.right")
                                    .foregroundColor(.white)
                                    .padding()
                                    .background(Color.primary)
                                    .clipShape(Circle())
                            }
                        }
                        .buttonStyle(PlainButtonStyle())
                    }
                }
                .padding(44)
            }
            
            .navigationDestination(isPresented: $navigateToDoctorList) {
                DestinationRouterView()
                    .navigationBarBackButtonHidden(true)
            }
        
        }
    }
}

private struct DestinationRouterView: View {
    @EnvironmentObject var sessionManager: SessionManager

    var body: some View {
        Group {
            if sessionManager.isLoggedIn {
                ServicesView()
            } else {
                LoginView()
            }
        }
    }
}

#Preview {
    LandingView()
        .environmentObject(SessionManager())
}
